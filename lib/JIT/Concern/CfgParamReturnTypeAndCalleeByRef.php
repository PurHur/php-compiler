<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Op;
use PHPCompiler\Block;
use PHPTypes\Type;

/**
 * CFG param/return type mapping and callee by-ref meta (#36387).
 *
 * Extracted from {@see CoerceReturnPropertyDeclaringAndByRef}:
 * {@code rawTypeFromCfgParam} through {@code nativeOrVarargReturnsByRef}.
 * Move-only Concern hollow toward split-TU / size-budget Done-when.
 *
 * php-src: Zend/zend_compile.c (param/return type trees), Zend/zend_execute.c
 * FLAG_RETURNS_REF / by-ref call sites — no new C ABI and no opcode/IR shape change.
 */
trait CfgParamReturnTypeAndCalleeByRef
{
    private function rawTypeFromCfgParam(\PHPCfg\Op\Expr\Param $param): Type
    {
        $declared = $this->declaredTypeFromCfgParam($param);
        if ($param->declaredType instanceof Op\Type\Literal
            && 'mixed' === strtolower($param->declaredType->name)
        ) {
            return Type::mixed();
        }
        if (null !== $declared && Type::TYPE_UNION === $declared->type) {
            return $declared;
        }
        // Prefer a resolved SSA result type, but never TYPE_UNKNOWN — PHPTypes leaves
        // `string $s` as UNKNOWN when the formal is read inside a loop, and treating
        // that as authoritative forced a boxed `__value__` ABI (and killed strlen/ord
        // native-string elision) (#36386).
        if (
            null !== $param->result->type
            && Type::TYPE_NULL !== $param->result->type->type
            && Type::TYPE_UNKNOWN !== $param->result->type->type
        ) {
            return $param->result->type;
        }
        if (null !== $declared) {
            return $declared;
        }
        if (null !== $param->result->type) {
            return $param->result->type;
        }

        return Type::mixed();
    }

    private function rawTypeFromCfgReturn(?\PHPCfg\Op\Type $returnType): ?Type
    {
        if (null === $returnType) {
            return null;
        }
        if ($returnType instanceof Op\Type\Literal) {
            // PHPTypes Type::fromDecl('mixed') mis-parses as object userType mixed (#12348 / #32728).
            if ('mixed' === strtolower($returnType->name)) {
                return Type::mixed();
            }

            return Type::fromDecl($returnType->name);
        }
        if ($returnType instanceof Op\Type\Reference && null !== $returnType->declaration) {
            $inner = $returnType->declaration;
            if ($inner instanceof \PHPCfg\Operand\Literal) {
                if (is_string($inner->value) && 'mixed' === strtolower($inner->value)) {
                    return Type::mixed();
                }

                return Type::fromDecl($inner->value);
            }
            if ($inner instanceof Op\Type\Literal) {
                if ('mixed' === strtolower($inner->name)) {
                    return Type::mixed();
                }

                return Type::fromDecl($inner->name);
            }
            try {
                return Type::fromTypeDecl($inner);
            } catch (\LogicException) {
                return null;
            }
        }
        try {
            return Type::fromTypeDecl($returnType);
        } catch (\LogicException) {
            return null;
        }
    }

    private function typeIncludesNull(Type $type): bool
    {
        if (Type::TYPE_NULL === $type->type) {
            return true;
        }
        if (Type::TYPE_UNION === $type->type) {
            foreach ($type->subTypes ?? [] as $sub) {
                if ($this->typeIncludesNull($sub)) {
                    return true;
                }
            }
        }

        return false;
    }

    private function cfgParamDeclaredTypeUsesDnfShape(\PHPCfg\Op\Expr\Param $param): bool
    {
        $declared = $param->declaredType;
        if (!$declared instanceof Op\Type) {
            return false;
        }
        if ($declared instanceof Op\Type\Union_ || $declared instanceof Op\Type\Intersection) {
            return true;
        }

        return $declared instanceof Op\Type\Nullable;
    }

    private function cfgParamIsImplicitNullable(Block $block, int $paramIdx): bool
    {
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type || (int) $op->arg2 !== $paramIdx) {
                continue;
            }

            return isset($block->paramImplicitNullable[(int) $op->arg1]);
        }

        return false;
    }

    private function callbackTypeFromPhptype(Type $type): ?string
    {
        $allowsNull = $this->typeIncludesNull($type);
        $type = $this->context->unwrapNullableUnionType($type);
        switch ($type->type) {
            case Type::TYPE_LONG:
                $callback = 'int64';
                break;
            case Type::TYPE_DOUBLE:
                $callback = 'double';
                break;
            case Type::TYPE_BOOLEAN:
                $callback = 'bool';
                break;
            case Type::TYPE_STRING:
                $callback = '__string__*';
                break;
            case Type::TYPE_OBJECT:
                // PHPTypes Type::fromDecl('mixed') → object userType mixed (#12348 / #32728).
                // Must use boxed `__value__`, not `__object__*` (offsetGet(): mixed returned the receiver).
                if ('mixed' === strtolower((string) ($type->userType ?? ''))) {
                    $callback = '__value__';
                    break;
                }
                // NestedJIT: VM Variable returns match param ABI (#16565 / #20785).
                // Without this, UnserializeJitHelper::decode lowers as __object__* and
                // thin-AOT always-helper bridges fail module verify (peer Serialize #20773).
                if (JIT\NestedJitCompileScope::isActive() && $this->isCfgVmVariableParamType($type)) {
                    $callback = '__value__*';
                } elseif (JIT\NestedJitCompileScope::isActive() && $this->isCfgVmHashTableParamType($type)) {
                    // CompareJitHelper::hashtableSpaceship etc. (#21109).
                    $callback = '__hashtable__*';
                } else {
                    $callback = '__object__*';
                }
                break;
            case Type::TYPE_ARRAY:
                $callback = '__hashtable__*';
                break;
            case Type::TYPE_NULL:
                $callback = '__value__';
                break;
            default:
                $callback = null;
                break;
        }
        if ($allowsNull && null !== $callback && '__value__' !== $callback && '__object__*' !== $callback) {
            return '__value__*';
        }

        return $callback;
    }

    private function cfgFunctionReturnsByRef(?\PHPCfg\Func $cfgFunc): bool
    {
        return null !== $cfgFunc
            && (($cfgFunc->flags ?? 0) & \PHPCfg\Func::FLAG_RETURNS_REF) !== 0;
    }

    /** @param string ...$names logical / proxy function names */
    private function markFunctionReturnsByRef(string ...$names): void
    {
        foreach ($names as $name) {
            $lc = strtolower($name);
            if ('' !== $lc) {
                $this->context->functionReturnsRef[$lc] = true;
            }
        }
    }

    private function calleeReturnsByRef(?JIT\Call $toCall): bool
    {
        if (null === $toCall) {
            return false;
        }
        // Closure use()/bind wrappers hide the Native proxy; display name is often "{closure}"
        // while functionReturnsRef is keyed by "{closure}_N" (#34759 / re-#34717).
        if (
            $toCall instanceof JIT\Call\ClosureWithCaptures
            || $toCall instanceof JIT\Call\ClosureWithBinding
        ) {
            $toCall = JIT\ClosureBindHelper::unwrapInnerCall($toCall);
        }
        if ($toCall instanceof JIT\Call\Native || $toCall instanceof JIT\Call\Vararg) {
            return $this->nativeOrVarargReturnsByRef($toCall);
        }
        if ($toCall instanceof JIT\Call\RuntimeIndirectClosureCall) {
            foreach ($toCall->candidates as $name => $candidate) {
                if (isset($this->context->functionReturnsRef[strtolower((string) $name)])) {
                    return true;
                }
                $inner = JIT\ClosureBindHelper::unwrapInnerCall($candidate);
                if (
                    ($inner instanceof JIT\Call\Native || $inner instanceof JIT\Call\Vararg)
                    && $this->nativeOrVarargReturnsByRef($inner)
                ) {
                    return true;
                }
            }

            return false;
        }
        if ($toCall instanceof JIT\Call\NestedClosureInvoke) {
            foreach (JIT\ClosureHelper::closureCandidates($this->context) as $name => $candidate) {
                if (isset($this->context->functionReturnsRef[strtolower((string) $name)])) {
                    return true;
                }
                $inner = JIT\ClosureBindHelper::unwrapInnerCall($candidate);
                if (
                    ($inner instanceof JIT\Call\Native || $inner instanceof JIT\Call\Vararg)
                    && $this->nativeOrVarargReturnsByRef($inner)
                ) {
                    return true;
                }
            }

            return false;
        }
        if (
            $toCall instanceof JIT\Call\RuntimeIndirectInstanceMethodCall
            || $toCall instanceof JIT\Call\RuntimeIndirectStaticMethodCall
            || $toCall instanceof JIT\Call\RuntimeVariableStaticMethodCall
        ) {
            $candidateList = $toCall instanceof JIT\Call\RuntimeVariableStaticMethodCall
                ? $toCall->candidatesByMethodLc
                : $toCall->candidatesByClassId;
            foreach ($candidateList as $candidate) {
                if (
                    ($candidate instanceof JIT\Call\Native || $candidate instanceof JIT\Call\Vararg)
                    && $this->nativeOrVarargReturnsByRef($candidate)
                ) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * True when a Native/Vararg callee was registered with FLAG_RETURNS_REF.
     *
     * Prefer display-name lookup (named functions), then match the LLVM Value against
     * {@see Context::$functions} so Closure proxies keyed as `{closure}_N` still hit
     * when Native::$name is the rich `{closure}` label (#34759).
     */
    private function nativeOrVarargReturnsByRef(JIT\Call\Native|JIT\Call\Vararg $toCall): bool
    {
        if (isset($this->context->functionReturnsRef[strtolower($toCall->name)])) {
            return true;
        }
        if ($toCall instanceof JIT\Call\Native) {
            foreach ($this->context->functions as $lc => $fn) {
                if ($fn === $toCall->function) {
                    return isset($this->context->functionReturnsRef[$lc]);
                }
            }
        }

        return false;
    }
}
