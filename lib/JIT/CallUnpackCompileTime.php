<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\JIT;
use PHPCompiler\OpCode;
use PHPCompiler\VM\CallUnpackJitHelper;
use PHPCompiler\VM\CallUnpackSupport;
use PHPCompiler\VM\NamedArgs as VmNamedArgs;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCfg\Operand;

/**
 * JIT compile-time call unpack lowering — uses VM PHP SSOT (#10202).
 */
final class CallUnpackCompileTime
{
    /**
     * @param list<Variable|array{unpack: Variable}|array{named: string, value: Variable}> $argEntries
     * @param list<Operand|null>                                                          $argOperands
     * @param list<string>                                                                $paramNames
     *
     * @return array{0: list<Variable>, 1: list<Operand|null>}|null
     */
    public static function tryResolveCompileTimeNamedUnpack(
        ?Block $block,
        array $argEntries,
        array $argOperands,
        array $paramNames,
        ?int $variadicIndex,
        JIT $jit,
        ?string $functionName = null
    ): ?array {
        if (null === $block || [] === $paramNames) {
            return null;
        }

        $vmEntries = [];
        foreach ($argEntries as $i => $entry) {
            if (\is_array($entry) && isset($entry['unpack'])) {
                $operand = $argOperands[$i] ?? null;
                if (!$operand instanceof Operand) {
                    return null;
                }
                $vmArray = self::tryCompileTimeArrayFromOperand($block, $operand);
                if (null === $vmArray) {
                    return null;
                }
                // Lists and string-keyed spreads both expand here (#24144 / #5031).
                foreach (
                    CallUnpackSupport::expandArrayEntries(
                        $vmArray,
                        $paramNames,
                        $variadicIndex,
                        $functionName
                    ) as $expanded
                ) {
                    $vmEntries[] = $expanded;
                }
                continue;
            }
            if (\is_array($entry) && isset($entry['named'])) {
                $vmValue = self::tryCompileTimeValueFromJitVariable($entry['value']);
                if (null === $vmValue) {
                    return null;
                }
                $vmEntries[] = ['n', $entry['named'], $vmValue];
                continue;
            }
            if ($entry instanceof Variable) {
                $vmValue = self::tryCompileTimeValueFromJitVariable($entry);
                if (null === $vmValue) {
                    return null;
                }
                $vmEntries[] = ['p', $vmValue];
                continue;
            }

            return null;
        }

        try {
            $vmResolved = VmNamedArgs::resolve(
                $vmEntries,
                $paramNames,
                $variadicIndex,
                $functionName,
                null !== $functionName
            );
        } catch (\ArgumentCountError|\Error|\TypeError|\ValueError $e) {
            // Runtime binding errors must not abort AOT compile (#23449).
            return null;
        }

        $jitResult = [];
        $jitOperands = [];
        foreach ($vmResolved as $idx => $vmVar) {
            $jitVar = $jit->jitVariableFromVmConstantForCallUnpack($vmVar->resolveIndirect());
            if (
                null !== $variadicIndex
                && (int) $idx === $variadicIndex
                && Variable::TYPE_HASHTABLE === $jitVar->type
            ) {
                $jitVar->variadicElementChecksDone = true;
            }
            $jitResult[(int) $idx] = $jitVar;
            $jitOperands[(int) $idx] = null;
        }
        ksort($jitResult);
        ksort($jitOperands);

        return [$jitResult, $jitOperands];
    }

    public static function tryCompileTimeArrayFromOperand(Block $block, Operand $operand): ?VmVariable
    {
        $slot = $block->getVarSlot($operand, true);
        // By-ref builtins (array_splice/sort/…) mutate the CV after INIT_ARRAY; folding
        // json_encode($a) from the pre-mutation literal prints the wrong AOT result (#27075).
        if (self::slotHasByRefMutation($block, $slot)) {
            return null;
        }

        return self::tryCompileTimeArrayFromSlot($block, $slot);
    }

    /**
     * True when $slot is ARG_SEND to a by-ref builtin parameter in this block.
     *
     * Conservative: any such send refuses compile-time array recovery (missed folds OK).
     */
    private static function slotHasByRefMutation(Block $block, int $slot): bool
    {
        $fn = null;
        $argIdx = 0;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCCALL_INIT === $op->type) {
                $fn = null;
                $argIdx = 0;
                if (null !== $op->arg1 && isset($block->constants[$op->arg1])) {
                    $const = $block->constants[$op->arg1];
                    if (VmVariable::TYPE_STRING === $const->type) {
                        $fn = strtolower($const->toString());
                    }
                }

                continue;
            }
            if (OpCode::TYPE_ARG_SEND === $op->type) {
                if (null !== $fn && $op->arg1 === $slot) {
                    if (\in_array($argIdx, BuiltinByRefParams::forFunction($fn), true)) {
                        return true;
                    }
                    $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($fn);
                    if (null !== $variadicFrom && $argIdx >= $variadicFrom) {
                        if (
                            'array_multisort' !== $fn
                            || !self::argSendLooksLikeMultisortFlag($block, $op)
                        ) {
                            return true;
                        }
                    }
                }
                ++$argIdx;

                continue;
            }
            if (
                OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $op->type
            ) {
                $fn = null;
                $argIdx = 0;
            }
        }

        return false;
    }

    private static function argSendLooksLikeMultisortFlag(Block $block, OpCode $op): bool
    {
        if (null === $op->arg1 || !isset($block->constants[$op->arg1])) {
            return false;
        }
        $const = $block->constants[$op->arg1];
        if (VmVariable::TYPE_INTEGER !== $const->type) {
            return false;
        }
        $n = $const->toInt();

        // SORT_* / SORT_FLAG_* range used by array_multisort (#9481).
        return $n >= 0 && $n <= 8;
    }

    private static function tryCompileTimeArrayFromSlot(Block $block, int $slot, array &$visited = []): ?VmVariable
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;

        if (isset($block->constants[$slot]) && VmVariable::TYPE_ARRAY === $block->constants[$slot]->type) {
            $copy = new VmVariable();
            $copy->copyFrom($block->constants[$slot]);

            return $copy;
        }

        $foundInit = false;
        $elements = [];
        /** @var \PHPCompiler\VM\HashTable|null $spreadHt */
        $spreadHt = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARRAY_SPREAD === $op->type && $op->arg1 === $slot) {
                // Materialize prior literal elements, then apply unpack (array_merge semantics).
                // NestedJIT cannot export runtime string-key HTs — json_encode folds here (#28673 /
                // peer #27546 array_merge).
                if (null === $spreadHt) {
                    if ($foundInit) {
                        $spreadHt = CallUnpackJitHelper::vmArrayFromElements($elements)->toArray();
                        $elements = [];
                    } else {
                        $spreadHt = new \PHPCompiler\VM\HashTable();
                        $foundInit = true;
                    }
                } elseif ([] !== $elements) {
                    foreach ($elements as [$key, $value]) {
                        if (null === $key) {
                            $spreadHt->append($value);
                        } elseif (VmVariable::TYPE_INTEGER === $key->type) {
                            $spreadHt->addIndex($key->toInt(), $value);
                        } elseif (VmVariable::TYPE_STRING === $key->type) {
                            $spreadHt->add($key->toString(), $value);
                        } else {
                            return null;
                        }
                    }
                    $elements = [];
                }
                if (null === $op->arg2) {
                    return null;
                }
                $src = self::tryCompileTimeArrayFromSlot($block, (int) $op->arg2, $visited);
                if (null === $src || VmVariable::TYPE_ARRAY !== $src->type) {
                    return null;
                }
                $spreadHt->spreadFrom($src->toArray());
                continue;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type && $op->arg1 === $slot) {
                // Slot reuse: a later INIT_ARRAY replaces the prior list (fold saw [1,2,3]×2 → #26977).
                $foundInit = true;
                $elements = [];
                $spreadHt = null;
                if (null !== $op->arg2) {
                    // null arg3 = list append (nextFreeElement); do not treat as missing key (#24144).
                    $key = null === $op->arg3 ? null : self::compileTimeKey($block, $op->arg3);
                    if (null !== $op->arg3 && null === $key) {
                        return null;
                    }
                    $value = self::compileTimeValueFromSlot($block, (int) $op->arg2, $visited);
                    if (null === $value) {
                        return null;
                    }
                    $elements[] = [$key, $value];
                }
                continue;
            }
            if (OpCode::TYPE_ADD_ARRAY_ELEMENT === $op->type && $op->arg1 === $slot) {
                $key = null === $op->arg3 ? null : self::compileTimeKey($block, $op->arg3);
                if (null !== $op->arg3 && null === $key) {
                    return null;
                }
                $value = self::compileTimeValueFromSlot($block, (int) $op->arg2, $visited);
                if (null === $value) {
                    return null;
                }
                if (null !== $spreadHt) {
                    if (null === $key) {
                        $spreadHt->append($value);
                    } elseif (VmVariable::TYPE_INTEGER === $key->type) {
                        $spreadHt->addIndex($key->toInt(), $value);
                    } elseif (VmVariable::TYPE_STRING === $key->type) {
                        $spreadHt->add($key->toString(), $value);
                    } else {
                        return null;
                    }
                } else {
                    $elements[] = [$key, $value];
                }
            }
        }

        if (null !== $spreadHt) {
            if ([] !== $elements) {
                foreach ($elements as [$key, $value]) {
                    if (null === $key) {
                        $spreadHt->append($value);
                    } elseif (VmVariable::TYPE_INTEGER === $key->type) {
                        $spreadHt->addIndex($key->toInt(), $value);
                    } elseif (VmVariable::TYPE_STRING === $key->type) {
                        $spreadHt->add($key->toString(), $value);
                    } else {
                        return null;
                    }
                }
            }
            $var = new VmVariable();
            $var->array($spreadHt);

            return $var;
        }

        if ($foundInit) {
            return CallUnpackJitHelper::vmArrayFromElements($elements);
        }

        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ASSIGN !== $op->type || $op->arg2 !== $slot) {
                continue;
            }

            return self::tryCompileTimeArrayFromSlot($block, (int) $op->arg3, $visited);
        }

        return null;
    }

    private static function compileTimeKey(Block $block, ?int $keySlot): ?VmVariable
    {
        if (null === $keySlot) {
            return null;
        }

        return self::compileTimeValue($block, $keySlot);
    }

    private static function compileTimeValue(Block $block, int $valueSlot): ?VmVariable
    {
        if (!isset($block->constants[$valueSlot])) {
            return null;
        }
        $copy = new VmVariable();
        $copy->copyFrom($block->constants[$valueSlot]);

        return $copy;
    }

    /**
     * @param array<int, true> $visited
     */
    private static function compileTimeValueFromSlot(Block $block, int $valueSlot, array &$visited = []): ?VmVariable
    {
        $literal = self::compileTimeValue($block, $valueSlot);
        if (null !== $literal) {
            return $literal;
        }

        return self::tryCompileTimeArrayFromSlot($block, $valueSlot, $visited);
    }

    public static function tryCompileTimeValueFromJitVariable(Variable $var): ?VmVariable
    {
        return self::compileTimeValueFromJitVariable($var);
    }

    private static function compileTimeValueFromJitVariable(Variable $var): ?VmVariable
    {
        if (null !== $var->compileTimeString) {
            $vm = new VmVariable(VmVariable::TYPE_STRING);
            $vm->string($var->compileTimeString);

            return $vm;
        }
        if ($var->isNullConstant) {
            $vm = new VmVariable(VmVariable::TYPE_NULL);

            return $vm;
        }
        if (Variable::TYPE_NATIVE_LONG === $var->type && Variable::KIND_VALUE === $var->kind) {
            $n = self::nativeConstIntFromJitVariable($var);
            if (null === $n) {
                return null;
            }
            $vm = new VmVariable(VmVariable::TYPE_INTEGER);
            $vm->int($n);

            return $vm;
        }
        if (Variable::TYPE_NATIVE_BOOL === $var->type && Variable::KIND_VALUE === $var->kind) {
            // fromLiteral stores bools as compileTimeLong 0/1; Value has no constInt() (#23218 AOT)
            $n = self::nativeConstIntFromJitVariable($var);
            if (null === $n) {
                return null;
            }
            $vm = new VmVariable(VmVariable::TYPE_BOOLEAN);
            $vm->bool(0 !== $n);

            return $vm;
        }

        return null;
    }

    /**
     * Read a compile-time native int/bool constant from a JIT value variable.
     * Prefer {@see Variable::$compileTimeLong}; fall back to LLVMConstIntGetZExtValue.
     */
    private static function nativeConstIntFromJitVariable(Variable $var): ?int
    {
        if (null !== $var->compileTimeLong) {
            return $var->compileTimeLong;
        }
        $llvmValue = $var->value;
        if (!isset($llvmValue->llvm, $llvmValue->value)) {
            return null;
        }
        $lib = $llvmValue->llvm->lib;
        if (null === $lib->LLVMIsAConstantInt($llvmValue->value)) {
            return null;
        }

        return (int) $lib->LLVMConstIntGetZExtValue($llvmValue->value);
    }
}
