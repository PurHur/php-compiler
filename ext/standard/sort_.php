<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

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
 * sort() for homogeneous packed string, integer, or object arrays (subset of PHP).
 *
 * VM: full support. JIT/AOT: dynamic hashtable arrays only (not fixed native literals).
 */
final class sort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('sort');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \LogicException('sort() requires one to three arguments');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        $ht = VmArray::requireArray($frame->calledArgs[0], 'sort');
        $flags = StdlibConstants::SORT_REGULAR;
        if ($argc > 1) {
            $flags = VmInternalCompare::resolveSortFunctionFlags($frame, 'sort');
        }
        if ($ht->getNumElements() < 2) {
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
                StdlibConstants::SORT_REGULAR === $sortType
                && VmInternalCompare::valuesShareScalarType($values, Variable::TYPE_STRING)
            ) {
                VmInternalCompare::sortVariableValues(
                    $values,
                    VmInternalCompare::stringCompareForSortFlags($flags)
                );
            } else {
                VmInternalCompare::sortVariableValuesWithFlags($values, $flags);
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
                        if ($a->toInt() <= $b->toInt()) {
                            break;
                        }
                        $tmp = $values[$j - 1];
                        $values[$j - 1] = $values[$j];
                        $values[$j] = $tmp;
                        --$j;
                    }
                }
            } else {
                VmInternalCompare::sortVariableValuesWithFlags($values, $flags);
            }
        } elseif (Variable::TYPE_OBJECT === $first->type || EnumCaseSupport::isEnumCaseVariable($first)) {
            VmInternalCompare::assertHomogeneousEnumOrObjectValues($values, 'sort()');
            if (self::objectSortUsesSpaceship($flags)) {
                VmInternalCompare::sortVariableValuesBySpaceship($values);
            } else {
                throw new \LogicException(
                    'sort() flags are not supported for object arrays in this compiler build'
                );
            }
        } else {
            VmInternalCompare::sortVariableValuesWithFlags($values, $flags);
        }
        $array->separateArrayForWrite();
        $array->resolveIndirect()->toArray()->replacePackedValues($values);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            throw new \LogicException('sort() requires one or two arguments');
        }
        JitArrayKey::requireArrayArg($context, $args[0], 'sort');
        if (1 === $argc) {
            SortRuntime::sortPacked($context, $args[0]);
        } else {
            self::jitSortWithFlags($context, $args[0], VmInternalCompare::resolveJitSortFlags($context, $args[1], 'sort'));
        }

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    private static function jitSortWithFlags(Context $context, JITVariable $array, int $flags): void
    {
        $caseFlag = $flags & StdlibConstants::SORT_FLAG_CASE;
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;

        if (StdlibConstants::SORT_NATURAL === $sortType) {
            if (0 !== $caseFlag) {
                SortRuntime::sortPackedNaturalCase($context, $array);
            } else {
                SortRuntime::sortPackedNatural($context, $array);
            }

            return;
        }
        if (StdlibConstants::SORT_LOCALE_STRING === $sortType) {
            SortRuntime::sortPackedLocale($context, $array);

            return;
        }
        if (
            StdlibConstants::SORT_REGULAR === $sortType
            || StdlibConstants::SORT_STRING === $sortType
            || StdlibConstants::SORT_NUMERIC === $sortType
        ) {
            SortRuntime::sortPacked($context, $array);

            return;
        }
        throw new \LogicException('sort() flags are not supported in this compiler build');
    }

    /** php-src php_array_sort — SORT_REGULAR uses zend_compare on object zvals. */
    private static function objectSortUsesSpaceship(int $flags): bool
    {
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;

        return StdlibConstants::SORT_REGULAR === $sortType
            || StdlibConstants::SORT_NUMERIC === $sortType;
    }

}
