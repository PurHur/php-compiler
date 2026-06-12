<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_multisort() — sort multiple packed arrays by the first (subset of PHP; issue #1212).
 *
 * VM: homogeneous string or integer arrays, same length, optional trailing SORT_ASC (4) or
 * SORT_DESC (3) for the primary array. JIT/AOT: coupled packed bubble sort (#1212).
 */
final class array_multisort extends Internal
{
    private const SORT_DESC = 3;
    private const SORT_ASC = 4;

    public function __construct()
    {
        parent::__construct('array_multisort');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1) {
            throw new \ArgumentCountError('array_multisort() expects at least 1 argument, 0 given');
        }
        $arrays = [];
        $descending = false;
        for ($i = 0; $i < $argc; ++$i) {
            $arg = $frame->calledArgs[$i]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $arg->type) {
                $arrays[] = $arg;
                continue;
            }
            if (Variable::TYPE_INTEGER === $arg->type
                || EnumCaseSupport::isEnumCaseVariable($arg)) {
                $order = VmArraySort::resolveMultisortOrderArg($arg);
                if (self::SORT_DESC === $order) {
                    $descending = true;
                } elseif (self::SORT_ASC !== $order) {
                    throw new \LogicException(
                        'array_multisort() only supports SORT_ASC or SORT_DESC in this compiler build'
                    );
                }
                continue;
            }
            throw new \LogicException(
                'array_multisort() arguments must be arrays or SORT_* order flags in this compiler build'
            );
        }
        if (\count($arrays) < 1) {
            throw new \LogicException(
                'array_multisort() requires at least one array argument in this compiler build'
            );
        }
        if (1 === \count($arrays)) {
            self::executeSingleArray($frame, $arrays[0], $descending);

            return;
        }
        $length = null;
        $primaryValues = [];
        foreach ($arrays as $idx => $array) {
            $ht = $array->toArray();
            $count = $ht->getNumElements();
            if (null === $length) {
                $length = $count;
            } elseif ($count !== $length) {
                throw new \LogicException(
                    'array_multisort() array lengths must match in this compiler build'
                );
            }
            if (0 === $idx) {
                foreach ($ht->iterate(true) as $value) {
                    $copy = new Variable();
                    $copy->copyFrom($value);
                    $primaryValues[] = $copy;
                }
            }
        }
        if (null === $length || $length < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $indices = range(0, $length - 1);
        self::sortIndicesByPrimary($indices, $primaryValues, $descending);
        foreach ($arrays as $array) {
            $ht = $array->toArray();
            $values = [];
            foreach ($ht->iterate(true) as $value) {
                $copy = new Variable();
                $copy->copyFrom($value);
                $values[] = $copy;
            }
            $reordered = [];
            foreach ($indices as $idx) {
                $reordered[] = $values[$idx];
            }
            $array->separateArrayForWrite();
            $array->resolveIndirect()->toArray()->replacePackedValues($reordered);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError('array_multisort() expects at least 1 argument, 0 given');
        }
        $arrays = [];
        $descending = false;
        for ($i = 0; $i < $argc; ++$i) {
            $arg = $args[$i];
            $order = self::tryResolveJitMultisortOrder($context, $arg);
            if (null !== $order) {
                if (self::SORT_DESC === $order) {
                    $descending = true;
                } elseif (self::SORT_ASC !== $order) {
                    throw new \LogicException(
                        'array_multisort() only supports SORT_ASC or SORT_DESC in this compiler build'
                    );
                }
                continue;
            }
            if (self::isJitArrayArg($arg)) {
                $arrays[] = $arg;
                continue;
            }
            throw new \LogicException(
                'array_multisort() arguments must be arrays or SORT_* order flags in this compiler build'
            );
        }
        if (\count($arrays) < 1) {
            throw new \LogicException(
                'array_multisort() requires at least one array argument in this compiler build'
            );
        }
        if (1 === \count($arrays)) {
            if ($descending) {
                ArrayBuiltinHelper::sortPackedReverse($context, $arrays[0]);
            } else {
                ArrayBuiltinHelper::sortPacked($context, $arrays[0]);
            }

            return $context->getTypeFromString('int1')->constInt(1, false);
        }
        ArrayBuiltinHelper::multisortPacked($context, $arrays, $descending);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    /**
     * php-src php_array_multisort: one array sorts that array in place (ext/standard/array.c, #4945).
     */
    private static function executeSingleArray(Frame $frame, Variable $array, bool $descending): void
    {
        $ht = $array->toArray();
        $length = $ht->getNumElements();
        if ($length < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $values = [];
        foreach ($ht->iterate(true) as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $values[] = $copy;
        }
        $indices = range(0, $length - 1);
        self::sortIndicesByPrimary($indices, $values, $descending);
        $reordered = [];
        foreach ($indices as $idx) {
            $reordered[] = $values[$idx];
        }
        $array->separateArrayForWrite();
        $array->resolveIndirect()->toArray()->replacePackedValues($reordered);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    /**
     * Sort index permutation by primary array values (no PHP closures — AOT self-host spine safe).
     *
     * @param list<int>      $indices
     * @param list<Variable> $primaryValues
     */
    private static function sortIndicesByPrimary(array &$indices, array $primaryValues, bool $descending): void
    {
        $first = $primaryValues[0]->resolveIndirect();
        $stringCompare = null;
        $useSpaceship = false;
        if (Variable::TYPE_STRING === $first->type) {
            $stringCompare = VmInternalCompare::resolveStringCallback('strcmp');
        } elseif (Variable::TYPE_INTEGER === $first->type) {
            // integer compare via compareIntegerPrimary()
        } elseif (Variable::TYPE_OBJECT === $first->type || EnumCaseSupport::isEnumCaseVariable($first)) {
            VmInternalCompare::assertHomogeneousEnumOrObjectValues($primaryValues, 'array_multisort()');
            $useSpaceship = true;
        } else {
            throw new \LogicException(
                'array_multisort() only supports homogeneous string or integer arrays in this compiler build'
            );
        }
        $n = \count($indices);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0) {
                if (null !== $stringCompare) {
                    $cmp = VmInternalCompare::invoke(
                        $stringCompare,
                        $primaryValues[$indices[$j - 1]],
                        $primaryValues[$indices[$j]]
                    );
                } elseif ($useSpaceship) {
                    $cmp = VmInternalCompare::comparePackedValuesForSort(
                        $primaryValues[$indices[$j - 1]],
                        $primaryValues[$indices[$j]]
                    );
                } else {
                    $cmp = self::compareIntegerPrimary(
                        $primaryValues[$indices[$j - 1]],
                        $primaryValues[$indices[$j]]
                    );
                }
                if ($descending) {
                    $cmp = -$cmp;
                }
                if ($cmp <= 0) {
                    break;
                }
                $tmp = $indices[$j - 1];
                $indices[$j - 1] = $indices[$j];
                $indices[$j] = $tmp;
                --$j;
            }
        }
    }

    private static function compareIntegerPrimary(Variable $a, Variable $b): int
    {
        $a = $a->resolveIndirect();
        $b = $b->resolveIndirect();
        if (Variable::TYPE_INTEGER !== $a->type || Variable::TYPE_INTEGER !== $b->type) {
            throw new \LogicException(
                'array_multisort() only supports homogeneous string or integer arrays in this compiler build'
            );
        }

        return $a->toInt() <=> $b->toInt();
    }

    private static function isJitArrayArg(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_HASHTABLE === ($arg->type & ~JITVariable::IS_NATIVE_ARRAY)
            || ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return true;
        }

        return JITVariable::TYPE_VALUE === $arg->type;
    }

    private static function tryResolveJitMultisortOrder(Context $context, JITVariable $arg): ?int
    {
        if (null !== $arg->compileTimeConstantName) {
            $lookup = strtolower($arg->compileTimeConstantName);
            if (isset(StdlibConstants::CORE_INT_BY_NAME[$lookup])) {
                return StdlibConstants::CORE_INT_BY_NAME[$lookup];
            }
            $phpVar = $context->runtime->vmContext->constantFetch($arg->compileTimeConstantName);
            if (null !== $phpVar && Variable::TYPE_INTEGER === $phpVar->type) {
                return $phpVar->toInt();
            }
        }
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type
            && JITVariable::KIND_VALUE === $arg->kind) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($arg->value->value)) {
                return (int) $lib->LLVMConstIntGetZExtValue($arg->value->value);
            }
        }

        return null;
    }
}
