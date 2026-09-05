<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCfg\Op;
use PHPCompiler\Block;
use PHPCompiler\JIT\Variable;
use PHPCompiler\OpCode;

/**
 * List-unpack assign targets, class-declare name/fatal helpers, and include
 * result assign (#36387).
 *
 * Extracted from {@see \PHPCompiler\JIT}: {@code listUnpackAssignTargetsInBlock}
 * through {@code assignOperandForced} so the hub shrinks toward split-TU
 * iterability under the size-budget ratchet.
 *
 * php-src: Zend/zend_compile.c class/interface/trait redeclare
 * (E_COMPILE_ERROR), Zend/zend_execute.c list()/[] destruct destinations,
 * Zend/zend_include.c include return value — move-only Concern extract; no
 * new C ABI and no opcode/IR shape change.
 */
trait ListUnpackClassDeclareAndIncludeAssign
{
    /**
     * Dest operands for guarded list() / [] destruct in this CFG block (#4531).
     *
     * @return list<Operand>
     */
    private function listUnpackAssignTargetsInBlock(Block $block): array
    {
        $targets = [];
        $seen = new \SplObjectStorage();
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type) {
                continue;
            }
            if (null === $op->arg2) {
                continue;
            }
            $dest = $block->getOperand($op->arg2);
            $name = JIT\OperandName::resolve($dest);
            if (null === $name || '' === $name) {
                continue;
            }
            if ($seen->contains($dest)) {
                continue;
            }
            $seen[$dest] = true;
            $targets[] = $dest;
        }

        return $targets;
    }

    /** `return $c ? $a : $b` nullable arm — direct return avoids AOT merge-slot segfault (#8555). */

    /** @return bool false when class return TypeError was emitted (skip ret) */
    private function emitJitClassReturnTypeCheck(Block $block, Variable $return): bool
    {
        return JIT\ClassReturnCheck::enforce($this->context, $block, $return);
    }

    /**
     * LLVM return type tag for a CFG function (must match compileBlock() signature lowering).
     */
    private function cfgFunctionReturnCallbackType(?\PHPCfg\Func $cfgFunc): ?string
    {
        if (null === $cfgFunc) {
            return null;
        }
        if ('__construct' === strtolower($cfgFunc->name)) {
            return 'void';
        }
        if ('__destruct' === strtolower($cfgFunc->name)) {
            return 'void';
        }
        // Literal `void`/`never` must win before rawTypeFromCfgReturn: Type::fromDecl('void')
        // is TYPE_NULL, and callbackTypeFromPhptype(TYPE_NULL) yields `__value__` — that wrongly
        // adds an sret slot and shifts every PHP arg (breaks MultipartNative etc., #5965).
        if ($cfgFunc->returnType instanceof Op\Type\Literal) {
            $lit = strtolower($cfgFunc->returnType->name);
            if ('void' === $lit || 'never' === $lit) {
                return 'void';
            }
            // Bare `: iterable` is Traversable|array — boxed `__value__` ABI, not class
            // `__object__*` (Type::fromDecl maps the name to TYPE_OBJECT, #29888).
            if ('iterable' === $lit) {
                return '__value__';
            }
        }
        if ($cfgFunc->returnType instanceof Op\Type\Void_) {
            return 'void';
        }
        if ($cfgFunc->returnType instanceof Op\Type\Never_) {
            return 'void';
        }
        if ($cfgFunc->returnType instanceof Op\Type\Nullable) {
            $rawReturn = $this->rawTypeFromCfgReturn($cfgFunc->returnType->subtype);
            if (null !== $rawReturn) {
                $callback = $this->callbackTypeFromPhptype($rawReturn);
                if (null !== $callback) {
                    // Nullable scalar returns use __value__* (param/return ABI parity with
                    // cfgParamIsImplicitNullable); non-nullable __string__* cannot carry null (#8563).
                    if ('__value__' !== $callback && '__object__*' !== $callback) {
                        return '__value__*';
                    }

                    return $callback;
                }
            }
        }
        $rawReturn = $this->rawTypeFromCfgReturn($cfgFunc->returnType);
        if (null !== $rawReturn) {
            $callback = $this->callbackTypeFromPhptype($rawReturn);
            if (null !== $callback) {
                return $callback;
            }
        }
        if ($cfgFunc->returnType instanceof Op\Type\Literal) {
            switch (strtolower($cfgFunc->returnType->name)) {
                case 'void':
                case 'never':
                    return 'void';
                case 'int':
                    return 'int64';
                case 'float':
                    return 'double';
                case 'string':
                    return '__string__*';
                case 'bool':
                    return 'bool';
                case 'object':
                    return '__object__*';
                case 'array':
                    return '__hashtable__*';
                case 'mixed':
                    // Avoid Type::fromDecl('mixed') → __object__* (#12348 / #32728).
                    return '__value__';
                default:
                    return '__value__';
            }
        }

        return '__value__';
    }

    /** Class const / property default lowering only; values live in $block->constants (self-host bundle). */
    private function isSelfHostClassBodyEpilogueOpcode(int $type): bool
    {
        return OpCode::TYPE_UNARY_MINUS === $type
            || OpCode::TYPE_PLUS === $type
            || OpCode::TYPE_MUL === $type
            || OpCode::TYPE_BITWISE_OR === $type
            || OpCode::TYPE_BITWISE_AND === $type
            || OpCode::TYPE_BITWISE_XOR === $type
            || OpCode::TYPE_SHIFT_LEFT === $type
            || OpCode::TYPE_SHIFT_RIGHT === $type;
    }

    /** Bootstrap fixture: compile only isSuperglobalName from bundled Web\\Superglobals (#816). */
    private function isBundledSuperglobalsClass(int $classId): bool
    {
        $name = strtolower($this->context->scope->className ?? '');

        return 'phpcompiler\\web\\superglobals' === $name || 'superglobals' === $name;
    }

    /**
     * DECLARE_* name slot may be a Temporary with the string in $block->constants (#22642).
     */
    private function jitResolveClassLikeDeclareNameOperand(Block $block, OpCode $op): ?Operand\Literal
    {
        $nameOp = $block->getOperand($op->arg1);
        if ($nameOp instanceof Operand\Literal && is_string($nameOp->value)) {
            return $nameOp;
        }
        if (isset($block->constants[$op->arg1])) {
            $const = $block->constants[$op->arg1];
            if (VM\Variable::TYPE_STRING === $const->type) {
                return new Operand\Literal($const->toString());
            }
        }

        return null;
    }

    /**
     * Zend E_COMPILE_ERROR when a second class/interface/trait/enum reuses a name (#31110).
     *
     * @return true when fatal IR was emitted and the DECLARE body must be skipped
     */
    private function emitDuplicateClassLikeDeclareFatalIfNeeded(
        OpCode $op,
        Block $block,
        string $kind,
        string $name
    ): bool {
        $object = $this->context->type->object;
        if (!$object->shouldRejectUserDeclare($name, $op)) {
            $object->recordUserDeclareOpcode($name, $op);

            return false;
        }
        JIT\ImplementsHierarchyJitGuard::emitCompileFatal(
            $this->context,
            sprintf('Cannot declare %s %s, because the name is already in use', $kind, $name),
            $block->scriptPath(),
            $op->sourceLocation
        );

        return true;
    }

    public function assignIncludeResult(Operand $result): void
    {
        if ([] !== $this->context->inlineIncludeReturnHolders) {
            $holder = $this->context->inlineIncludeReturnHolders[
                array_key_last($this->context->inlineIncludeReturnHolders)
            ];
            $this->assignOperand($result, $holder, true);

            return;
        }
        if ([] !== $this->context->inlineIncludeReturnOperands) {
            $holderOp = $this->context->inlineIncludeReturnOperands[
                array_key_last($this->context->inlineIncludeReturnOperands)
            ];
            $this->assignOperand($result, $this->context->getVariableFromOp($holderOp), true);

            return;
        }
        $this->assignOperand(
            $result,
            new Variable(
                $this->context,
                Variable::TYPE_NATIVE_LONG,
                Variable::KIND_VALUE,
                $this->context->constantFromInteger(1)
            )
        );
    }

    public function assignOperandForced(Operand $result, Variable $value): void
    {
        $this->assignOperand($result, $value, true);
    }
}
