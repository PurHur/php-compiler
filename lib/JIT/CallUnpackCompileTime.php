<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\Block;
use PHPCompiler\JIT;
use PHPCompiler\OpCode;
use PHPCompiler\VM\CallUnpackJitHelper;
use PHPCompiler\VM\CallUnpackSupport;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\ext\standard\VmArray;
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
                if (VmArray::isList($vmArray->toArray())) {
                    return null;
                }
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

        $jitEntries = self::jitEntriesFromVmEntries($vmEntries, $jit);
        $jitOperands = array_fill(0, \count($jitEntries), null);

        return NamedArgs::resolveOutgoing($jitEntries, $jitOperands, $paramNames, $variadicIndex, $functionName);
    }

    public static function tryCompileTimeArrayFromOperand(Block $block, Operand $operand): ?VmVariable
    {
        return self::tryCompileTimeArrayFromSlot($block, $block->getVarSlot($operand, true));
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
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARRAY_SPREAD === $op->type && $op->arg1 === $slot) {
                return null;
            }
            if (OpCode::TYPE_INIT_ARRAY === $op->type && $op->arg1 === $slot) {
                $foundInit = true;
                if (null !== $op->arg2) {
                    $key = self::compileTimeKey($block, $op->arg3);
                    $value = self::compileTimeValue($block, (int) $op->arg2);
                    if (null === $key || null === $value) {
                        return null;
                    }
                    $elements[] = [$key, $value];
                }
                continue;
            }
            if (OpCode::TYPE_ADD_ARRAY_ELEMENT === $op->type && $op->arg1 === $slot) {
                $key = self::compileTimeKey($block, $op->arg3);
                $value = self::compileTimeValue($block, (int) $op->arg2);
                if (null === $key || null === $value) {
                    return null;
                }
                $elements[] = [$key, $value];
            }
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
     * @param list<array{0: string, 1?: mixed, 2?: VmVariable}> $vmEntries
     *
     * @return list<Variable|array{named: string, value: Variable}>
     */
    private static function jitEntriesFromVmEntries(array $vmEntries, JIT $jit): array
    {
        $out = [];
        foreach ($vmEntries as $entry) {
            if ('p' === $entry[0]) {
                $out[] = $jit->jitVariableFromVmConstantForCallUnpack($entry[1]);
                continue;
            }
            $out[] = [
                'named' => (string) $entry[1],
                'value' => $jit->jitVariableFromVmConstantForCallUnpack($entry[2]),
            ];
        }

        return $out;
    }

    private static function tryCompileTimeValueFromJitVariable(Variable $var): ?VmVariable
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
            $vm = new VmVariable(VmVariable::TYPE_INTEGER);
            $vm->int((int) $var->value->constInt(false));

            return $vm;
        }
        if (Variable::TYPE_NATIVE_BOOL === $var->type && Variable::KIND_VALUE === $var->kind) {
            $vm = new VmVariable(VmVariable::TYPE_BOOLEAN);
            $vm->bool((bool) $var->value->constInt(false));

            return $vm;
        }

        return null;
    }
}
