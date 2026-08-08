<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\SortRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * rsort() — sort by value descending, reindex (subset of PHP; issue #2300, #9123).
 *
 * VM: homogeneous packed string or integer lists. JIT/AOT: SortJitHelper via SortRuntime.
 */
final class rsort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('rsort');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        VmArraySort::assertFlagSortArgCount($argc, 'rsort');
        $array = $frame->calledArgs[0]->resolveIndirect();
        $ht = VmArray::requireArray($frame->calledArgs[0], 'rsort');
        $flags = StdlibConstants::SORT_REGULAR;
        if ($argc > 1) {
            $flags = VmInternalCompare::resolveSortFunctionFlags($frame, 'rsort');
        }
        $n = $ht->getNumElements();
        if (0 === $n) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        if (1 === $n) {
            // php-src still assigns new keys 0..n-1 for a single element (#25385).
            $array->separateArrayForWrite();
            VmArray::reindexToListKeys($array->resolveIndirect()->toArray());
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
        $first = $values[0]->resolveIndirect();
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (Variable::TYPE_STRING === $first->type) {
            if (
                StdlibConstants::SORT_STRING === $sortType
                && VmInternalCompare::valuesShareScalarType($values, Variable::TYPE_STRING)
            ) {
                VmInternalCompare::sortVariableValuesDesc(
                    $values,
                    VmInternalCompare::stringCompareForSortFlags($flags)
                );
            } else {
                VmInternalCompare::sortVariableValuesWithFlagsDesc($values, $flags);
            }
        } elseif (Variable::TYPE_INTEGER === $first->type) {
            if (
                StdlibConstants::SORT_REGULAR === $sortType
                && VmInternalCompare::valuesShareScalarType($values, Variable::TYPE_INTEGER)
            ) {
                $n = \count($values);
                for ($i = 1; $i < $n; ++$i) {
                    $j = $i;
                    while ($j > 0) {
                        $a = $values[$j - 1]->resolveIndirect();
                        $b = $values[$j]->resolveIndirect();
                        if ($a->toInt() >= $b->toInt()) {
                            break;
                        }
                        $tmp = $values[$j - 1];
                        $values[$j - 1] = $values[$j];
                        $values[$j] = $tmp;
                        --$j;
                    }
                }
            } else {
                VmInternalCompare::sortVariableValuesWithFlagsDesc($values, $flags);
            }
        } elseif (
            (Variable::TYPE_OBJECT === $first->type || EnumCaseSupport::isEnumCaseVariable($first))
            && VmInternalCompare::valuesAreEnumOrObjectOnly($values)
        ) {
            // Homogeneous object/enum arrays — php-src zend_compare on objects (#7466).
            if (self::objectSortUsesSpaceship($flags)) {
                VmInternalCompare::sortVariableValuesBySpaceshipDesc($values);
            } else {
                throw new \LogicException(
                    'rsort() flags are not supported for object arrays in this compiler build'
                );
            }
        } else {
            // Mixed object+scalar: Zend coerces object→1 with Notice (#29121 / #12243).
            VmInternalCompare::sortVariableValuesWithFlagsDesc($values, $flags);
        }
        $array->separateArrayForWrite();
        VmArray::writeReindexedValues($array->resolveIndirect()->toArray(), $values);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            \PHPCompiler\JIT\ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $argc < 1
                    ? \sprintf('rsort() expects at least 1 argument, %d given', $argc)
                    : \sprintf('rsort() expects at most 2 arguments, %d given', $argc)
            );

            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        JitArrayKey::requireArrayArg($context, $args[0], 'rsort');
        if (1 === $argc) {
            SortRuntime::sortPackedReverse($context, $args[0]);
        } else {
            self::jitSortWithFlags($context, $args[0], VmInternalCompare::resolveJitSortFlags($context, $args[1], 'rsort'));
        }

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    private static function jitSortWithFlags(Context $context, JITVariable $array, int $flags): void
    {
        $caseFlag = $flags & StdlibConstants::SORT_FLAG_CASE;
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;

        if (StdlibConstants::SORT_NATURAL === $sortType) {
            if (0 !== $caseFlag) {
                throw new \LogicException('rsort() flags are not supported in JIT/AOT in this compiler build');
            }
            throw new \LogicException('rsort() flags are not supported in JIT/AOT in this compiler build');
        }
        if (StdlibConstants::SORT_LOCALE_STRING === $sortType) {
            throw new \LogicException('rsort() flags are not supported in JIT/AOT in this compiler build');
        }
        if (
            StdlibConstants::SORT_REGULAR === $sortType
            || StdlibConstants::SORT_STRING === $sortType
            || StdlibConstants::SORT_NUMERIC === $sortType
        ) {
            SortRuntime::sortPackedReverse($context, $array);

            return;
        }
        throw new \LogicException('rsort() flags are not supported in this compiler build');
    }

    /** php-src php_array_sort — SORT_REGULAR uses zend_compare on object zvals. */
    private static function objectSortUsesSpaceship(int $flags): bool
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;

        return StdlibConstants::SORT_REGULAR === $sortType
            || StdlibConstants::SORT_NUMERIC === $sortType;
    }
}
