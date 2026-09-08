<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\VM\CallableCheck;
use PHPCompiler\VM\DnfCheck;
use PHPCompiler\VM\IterableCheck;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\Variable;

/**
 * VM TYPE_ARG_RECV dispatch (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}::runFramesInner ARG_RECV case body
 * (php-src Zend/zend_vm_def.h ZEND_RECV / ZEND_RECV_INIT / ZEND_RECV_VARIADIC;
 * zend_execute.c typed parameter binding). Concern trait — same namespace as
 * parent so relative Frame / OpCode helpers resolve. Move-only; no new C ABI.
 *
 * @return Frame|int|null Frame → restart runFramesInner; int → return from
 *         runFramesInner (SUCCESS/EXCEPTION/FAILURE); null → opcode complete.
 */
trait ArgRecvDispatch
{
    /**
     * Execute TYPE_ARG_RECV for the current opcode.
     *
     * @return Frame|int|null
     */
    private function executeArgRecvDispatch(Frame $frame, OpCode $op): Frame|int|null
    {
        $arg1 = $frame->scope[$op->arg1];
        $recvIdx = $op->arg2;
        if (
            null !== $frame->block->func
            && null !== $frame->block->func->class
            && !(($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)
            && !(($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE)
        ) {
            ++$recvIdx;
        }
        $isVariadicSlot = null !== $frame->block->variadicParamIndex
            && $frame->block->variadicParamIndex === (int) $op->arg2;
        if ($isVariadicSlot) {
            $variadicSlot = (int) $op->arg1;
            $variadicParamIdx = (int) $op->arg2;
            $paramCount = count($frame->block->paramNames);
            $strict = null !== $frame->parent
                ? $frame->parent->block->strictTypes
                : $frame->block->strictTypes;
            $maxArgIdx = -1;
            foreach (array_keys($frame->calledArgs) as $argKey) {
                if ($argKey > $maxArgIdx) {
                    $maxArgIdx = $argKey;
                }
            }
            $hasTrailingFixedAfterVariadic = $variadicParamIdx < $paramCount - 1;
            if ($hasTrailingFixedAfterVariadic) {
                $trailingCount = $paramCount - $variadicParamIdx - 1;
                $numProvided = $maxArgIdx + 1;
                $numToTrailing = min(
                    $trailingCount,
                    max(0, $numProvided - $variadicParamIdx - 1)
                );
                $variadicEndIdx = $numProvided - $numToTrailing - 1;
            } else {
                $variadicEndIdx = $maxArgIdx;
            }
            try {
                $variadicArgCount = 0;
                for ($i = $recvIdx; $i <= $variadicEndIdx; ++$i) {
                    if (array_key_exists($i, $frame->calledArgs)) {
                        ++$variadicArgCount;
                    }
                }
                $needsElementChecks = TypeCheck::variadicSlotNeedsElementChecks(
                    $frame->block,
                    $variadicSlot
                );
                $namedVariadicPack = null;
                $untypedNamedPassthrough = null;
                if (
                    1 === $variadicArgCount
                    && array_key_exists($recvIdx, $frame->calledArgs)
                ) {
                    $sole = $frame->calledArgs[$recvIdx]->resolveIndirect();
                    if (Variable::TYPE_ARRAY === $sole->type) {
                        if ($sole->namedVariadicPack) {
                            $namedVariadicPack = $sole;
                        } elseif (
                            !$needsElementChecks
                            && !$sole->toArray()->isPackedList()
                        ) {
                            $untypedNamedPassthrough = $sole;
                        }
                    }
                }
                if ($needsElementChecks) {
                    $trailing = [];
                    $trailingArgIndexes = [];
                    if (null !== $namedVariadicPack) {
                        $packOffset = 0;
                        foreach ($namedVariadicPack->toArray()->iterate(true) as $value) {
                            $trailing[] = $value;
                            // Zend Argument #N is call-site order; named packs start at the variadic slot (#19695).
                            $trailingArgIndexes[] = $variadicParamIdx + $packOffset;
                            ++$packOffset;
                        }
                    } else {
                        for ($i = $recvIdx; $i <= $variadicEndIdx; ++$i) {
                            if (array_key_exists($i, $frame->calledArgs)) {
                                $trailing[] = $frame->calledArgs[$i];
                                $trailingArgIndexes[] = $i;
                            }
                        }
                    }
                    $vmContext = $this->context;
                    TypeCheck::withParamErrorContext(
                        \PHPCompiler\VM\UserParamErrorContext::forRecvFrame($frame, $variadicParamIdx, true),
                        static function () use (
                            $trailing,
                            $trailingArgIndexes,
                            $strict,
                            $frame,
                            $variadicSlot,
                            $vmContext
                        ): void {
                            TypeCheck::verifyVariadicElements(
                                $trailing,
                                $strict,
                                $frame->block->paramVariadicElementTypeConstraints[$variadicSlot] ?? null,
                                $frame->block->paramVariadicElementGenericArrayTypeSpecs[$variadicSlot] ?? null,
                                $frame->block->paramVariadicElementIntersectionConstraints[$variadicSlot] ?? null,
                                $frame->block->paramVariadicElementDnfConstraints[$variadicSlot] ?? null,
                                $vmContext,
                                isset($frame->block->paramIterableSlots[$variadicSlot]),
                                isset($frame->block->paramNeverSlots[$variadicSlot]),
                                $frame->block->paramVariadicElementIntersectionDisplayLabels[$variadicSlot] ?? null,
                                $trailingArgIndexes
                            );
                        }
                    );
                }
                if (null !== $namedVariadicPack || null !== $untypedNamedPassthrough) {
                    $arg1->copyFrom($namedVariadicPack ?? $untypedNamedPassthrough);
                    $this->markScopeSlotInitialized($frame, (int) $op->arg1);

                    return null;
                }
                $arg1->newArray();
                $packed = $arg1->toArray();
                $variadicByRef = isset($frame->block->paramByRef[$variadicParamIdx]);
                for ($i = $recvIdx; $i <= $variadicEndIdx; ++$i) {
                    if (!array_key_exists($i, $frame->calledArgs)) {
                        continue;
                    }
                    $copy = new Variable();
                    if ($variadicByRef) {
                        $src = $frame->calledArgs[$i];
                        if ($copy !== $src) {
                            $copy->indirect($src);
                        } else {
                            $copy->copyFrom($src);
                        }
                    } else {
                        $copy->copyFrom($frame->calledArgs[$i]);
                    }
                    $packed->append($copy);
                }
            } catch (\TypeError $e) {
                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            }
            $this->markScopeSlotInitialized($frame, (int) $op->arg1);

            return null;
        }
        if (array_key_exists($recvIdx, $frame->calledArgs)) {
            if (isset($frame->block->paramByRef[(int) $op->arg2])) {
                $src = $frame->calledArgs[$recvIdx];
                // Avoid self-indirect when callee param slot aliases the argument (#5023).
                if ($arg1 !== $src) {
                    $arg1->indirect($src);
                }
            } else {
                $arg1->copyFrom($frame->calledArgs[$recvIdx]);
            }
        } elseif (
            (
                (null !== $op->arg3 && isset($frame->block->constants[$op->arg3]))
                || isset($frame->block->paramRuntimeDefaultInitBlocks[(int) $op->arg2])
            )
            && VM\ParamArgumentCountError::parameterIsEffectivelyRequired(
                $frame->block,
                (int) $op->arg2
            )
        ) {
            // Optional-before-required: do not apply the syntactic default (#25728).
            // Named hole (later arg present) → "Argument #N ($name) not passed";
            // otherwise Zend too-few wording.
            $error = VM\ParamArgumentCountError::calledArgsHaveIndexAbove(
                $frame->calledArgs,
                $recvIdx
            )
                ? VM\ParamArgumentCountError::forNamedArgNotPassed($frame, (int) $op->arg2)
                : VM\ParamArgumentCountError::forTooFewAtReceive($frame, (int) $op->arg2);
            $catchFrame = $this->dispatchVmArgumentCountError($error, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        } elseif (null !== $op->arg3 && isset($frame->block->constants[$op->arg3])) {
            $default = $frame->block->constants[$op->arg3];
            if (VM\EnumCaseSupport::isEnumCaseVariable($default)) {
                $arg1->copyFrom(
                    VM\EnumCaseSupport::materializeConstantValue($this->context, $default)
                );
            } else {
                $arg1->copyFrom($default);
            }
        } elseif (isset($frame->block->paramRuntimeDefaultInitBlocks[(int) $op->arg2])) {
            $paramIdx = (int) $op->arg2;
            $initBlock = $frame->block->paramRuntimeDefaultInitBlocks[$paramIdx];
            $resultSlot = $frame->block->paramRuntimeDefaultResultSlots[$paramIdx]
                ?? throw new \LogicException('Missing runtime parameter default result slot');
            $value = $this->executePropertyDefaultInitBlock($initBlock, $resultSlot);
            $arg1->copyFrom($value);
        } else {
            // Named/unpack omission of a required param (no default): Zend uses
            // "Argument #N ($name) not passed" when a later slot was supplied (#29095).
            $error = VM\ParamArgumentCountError::calledArgsHaveIndexAbove(
                $frame->calledArgs,
                $recvIdx
            )
                ? VM\ParamArgumentCountError::forNamedArgNotPassed($frame, (int) $op->arg2)
                : VM\ParamArgumentCountError::forTooFewAtReceive($frame, (int) $op->arg2);
            $catchFrame = $this->dispatchVmArgumentCountError($error, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }

            return null;
        }
        $strict = null !== $frame->parent
            ? $frame->parent->block->strictTypes
            : $frame->block->strictTypes;
        $arraySpec = $frame->block->paramGenericArrayTypeSpecs[$op->arg1] ?? null;
        $paramIdx = (int) $op->arg2;
        $vmContext = $this->context;
        try {
            TypeCheck::withParamErrorContext(
                \PHPCompiler\VM\UserParamErrorContext::forRecvFrame($frame, $paramIdx),
                function () use ($frame, $op, $arg1, $strict, $arraySpec, $vmContext): void {
                    if (
                        !TypeCheck::skipParameterTypeCheckForImplicitNullable(
                            $frame->block,
                            (int) $op->arg1,
                            $arg1
                        )
                    ) {
                        if (isset($frame->block->paramNeverSlots[$op->arg1])) {
                            TypeCheck::assertNeverParameter($arg1);
                        } elseif (isset($frame->block->paramIterableSlots[$op->arg1])) {
                            IterableCheck::assertParameter($arg1, $vmContext);
                        } elseif (isset($frame->block->paramCallableSlots[$op->arg1])) {
                            CallableCheck::assertParameter($arg1, $vmContext, $frame);
                        } elseif (isset($frame->block->paramDnfConstraints[$op->arg1])) {
                            DnfCheck::assertMatches(
                                $arg1,
                                $frame->block->paramDnfConstraints[$op->arg1],
                                $vmContext,
                                'Argument',
                                null,
                                $strict
                            );
                        } elseif (isset($frame->block->paramIntersectionConstraints[$op->arg1])) {
                            TypeCheck::assertParamIntersection(
                                $arg1,
                                $frame->block->paramIntersectionConstraints[$op->arg1],
                                $vmContext,
                                $frame->block->paramIntersectionDisplayLabels[$op->arg1] ?? null
                            );
                        } else {
                            TypeCheck::coerceParameter($arg1, $strict, $arraySpec);
                        }
                    }
                }
            );
        } catch (\TypeError $e) {
            $catchFrame = $this->dispatchVmTypeError($e, $frame);
            if (null !== $catchFrame) {
                if (null !== $frame->propertyHookRawProperty) {
                    $this->context->propertyHookExternalCatchFrame = $catchFrame;
                    $this->context->propertyHookSetAborted = true;

                    return self::FAILURE;
                }

                return $catchFrame;
            }
        }
        $this->markScopeSlotInitialized($frame, (int) $op->arg1);

        return null;
    }
}
