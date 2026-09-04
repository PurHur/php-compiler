<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCfg\Operand;
use PHPCompiler\JIT\Variable;
use PHPCompiler\Block;

/**
 * By-ref call-arg adaptation for internal/builtin functions (#36403).
 */
trait AdaptByRefCallArgs
{
    private function adaptByRefCallArgsForInternal(string $name, array $args, array $operands, Block $block): array
    {
        $byRef = BuiltinByRefParams::forFunction($name);
        foreach ($byRef as $idx) {
            if (!isset($args[$idx])) {
                continue;
            }
            $operand = $operands[$idx] ?? null;
            if (null === $operand) {
                continue;
            }
            if (
                'array_multisort' === strtolower($name)
                && !self::jitArgLooksLikeArray($args[$idx])
            ) {
                continue;
            }
            if (
                0 === $idx
                && VM\ReferencableCheck::defersByRefForNullStreamContextSetter($name)
                && (
                    Variable::TYPE_NULL === $args[$idx]->type
                    || $args[$idx]->isNullConstant
                )
            ) {
                continue;
            }
            if (
                VM\ReferencableCheck::skipsByRefWhenNotArray($name)
                && !self::jitArgLooksLikeArray($args[$idx])
            ) {
                if (!JIT\JitReferencableCheck::isOperandReferenceable($operand, $args[$idx])) {
                    if (
                        Variable::TYPE_NULL === $args[$idx]->type
                        || $args[$idx]->isNullConstant
                    ) {
                        JIT\JitReferencableCheck::emitNonVariableByRefNotice($this->context);
                        JIT\JitReferencableCheck::emitByRefError($this->context, $name, $idx);
                    } elseif (
                        'array_splice' === strtolower($name)
                        && null !== $operand
                        && VM\ReferencableCheck::operandIsObjectCast($operand, $block)
                    ) {
                        JIT\JitReferencableCheck::emitByRefError($this->context, $name, $idx);
                    } elseif (
                        !(
                            'array_splice' === strtolower($name)
                            && self::jitArgIsArrayOrObject($args[$idx])
                            && !self::jitArgLooksLikeArray($args[$idx])
                        )
                    ) {
                        JIT\JitReferencableCheck::emitNonVariableByRefNotice($this->context);
                    }
                }
                continue;
            }
            $namedLocalSlot = $block->slotForOperand($operand);
            if (null !== $namedLocalSlot && $block->isNamedVariableSlot((int) $namedLocalSlot)) {
                // Same promote as the generic path — bare referenceCapture left other SSA
                // operands for the slot on the pre-call native binding under thin AOT (#27090 /
                // peer #24162 ensureValueBoxLvalueForByRefPass).
                $args[$idx] = $this->ensureValueBoxLvalueForByRefPass($operand, $args[$idx]);
                continue;
            }
            if (!JIT\JitReferencableCheck::isOperandReferenceable($operand, $args[$idx])) {
                if (
                    0 === $idx
                    && VM\ReferencableCheck::allowsNonVariableObjectByRef($name)
                    && self::jitArgIsArrayOrObject($args[$idx])
                ) {
                    if (null !== $operand && VM\ReferencableCheck::operandIsObjectCast($operand, $block)) {
                        JIT\JitReferencableCheck::emitByRefError($this->context, $name, $idx);

                        continue;
                    }
                    // Inline array literals must Error before callback validation (#10819, #16259).
                    if (JIT\JitReferencableCheck::isEphemeralArrayArg($args[$idx])) {
                        JIT\JitReferencableCheck::emitByRefError($this->context, $name, $idx);

                        continue;
                    }
                    if (VM\ReferencableCheck::shouldEmitNonVariableObjectByRefNoticeAtCompileTime($operand, $block)) {
                        JIT\JitReferencableCheck::emitNonVariableByRefNotice($this->context);
                    }
                    continue;
                }
                if (
                    0 === $idx
                    && VM\ReferencableCheck::allowsEphemeralArrayLiteralByRef($name)
                    && JIT\JitReferencableCheck::isEphemeralArrayArg($args[$idx])
                ) {
                    continue;
                }
                if (
                    0 === $idx
                    && VM\ReferencableCheck::allowsEphemeralArrayLiteralByRef($name)
                    && !self::jitArgIsArrayOrObject($args[$idx])
                ) {
                    ext\standard\JitArrayElem::requireArrayParam(
                        $this->context,
                        $args[$idx],
                        $name,
                        1,
                        'array'
                    );
                    continue;
                }
                // reset/end/next/prev on call/method return temps — E_NOTICE + mutate temp (#25815).
                if (
                    0 === $idx
                    && VM\ReferencableCheck::isArrayInternalPointerMutatorBuiltin($name)
                    && VM\ReferencableCheck::operandIsFuncCallReturn($operand, $block)
                    && self::jitArgIsArrayOrObject($args[$idx])
                ) {
                    JIT\JitReferencableCheck::emitNonVariableByRefNotice($this->context);
                    $args[$idx]->nonVariableByRefTempAllowed = true;
                    continue;
                }
                JIT\JitReferencableCheck::emitByRefError($this->context, $name, $idx);
                // Mutator literals must not reach guardArrayMutatorByRefArg (#10295 / #25815).
                if (VM\ReferencableCheck::isArrayInternalPointerMutatorBuiltin($name)) {
                    $this->context->builder->call($this->context->lookupFunction('abort'));
                }

                continue;
            }
            $args[$idx] = $this->ensureValueBoxLvalueForByRefPass($operand, $args[$idx]);
        }
        $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($name);
        if (null !== $variadicFrom) {
            $n = \count($args);
            for ($idx = $variadicFrom; $idx < $n; ++$idx) {
                if (!isset($args[$idx])) {
                    continue;
                }
                $operand = $operands[$idx] ?? null;
                if (null === $operand) {
                    // Sparse operand maps (optional middle params) still send by-ref
                    // variadic tails — promote the live Variable (#35315 mb_convert_variables).
                    if (
                        Variable::KIND_VARIABLE === $args[$idx]->kind
                        || Variable::TYPE_VALUE === $args[$idx]->type
                    ) {
                        $args[$idx] = JIT\ClosureHelper::referenceCapture($this->context, $args[$idx]);
                    }
                    continue;
                }
                $scopeName = JIT\OperandName::resolve($operand);
                if (
                    null !== $scopeName
                    && '' !== $scopeName
                    && $block->isMainScript()
                    && !\PHPCompiler\Web\Superglobals::isSuperglobalName($scopeName)
                ) {
                    $args[$idx] = $this->context->ensureScriptGlobal($scopeName);
                }
                if (
                    'array_multisort' === strtolower($name)
                    && !self::jitArgLooksLikeArray($args[$idx])
                ) {
                    continue;
                }
                if (!JIT\JitReferencableCheck::isOperandReferenceable($operand, $args[$idx])) {
                    if (
                        VM\ReferencableCheck::allowsEphemeralArrayLiteralByRef($name)
                        && JIT\JitReferencableCheck::isEphemeralArrayArg($args[$idx])
                    ) {
                        continue;
                    }
                    JIT\JitReferencableCheck::emitByRefError($this->context, $name, $idx);

                    continue;
                }
                $args[$idx] = $this->ensureValueBoxLvalueForByRefPass($operand, $args[$idx]);
            }
        }

        return $args;
    }

    private static function jitArgLooksLikeArray(JIT\Variable $arg): bool
    {
        if (JIT\Variable::TYPE_HASHTABLE === ($arg->type & ~JIT\Variable::IS_NATIVE_ARRAY)
            || JIT\ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return true;
        }

        return JIT\Variable::TYPE_VALUE === $arg->type;
    }

    private static function jitArgIsArrayOrObject(JIT\Variable $arg): bool
    {
        if (JIT\Variable::TYPE_HASHTABLE === $arg->type
            || JIT\ArrayBuiltinHelper::isNativeArray($arg->type)
            || JIT\Variable::TYPE_OBJECT === $arg->type
        ) {
            return true;
        }

        return false;
    }

    private static function jitArgIsObject(JIT\Variable $arg): bool
    {
        return JIT\Variable::TYPE_OBJECT === $arg->type;
    }
}
