<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Block;
use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\OpCode;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPCfg\Operand;

/**
 * Invoke binary stdlib Internal comparators from other VM builtins (string callbacks).
 */
final class VmInternalCompare
{
    /** @var array<string, class-string> */
    private const STRING_CALLBACKS = [
        'strcmp' => strcmp::class,
        'strcasecmp' => strcasecmp::class,
        'strcoll' => strcoll::class,
        'strnatcmp' => strnatcmp::class,
        'strnatcasecmp' => strnatcasecmp::class,
    ];

    public static function resolveStringCallback(string $name): Internal
    {
        $lc = strtolower($name);
        if (!isset(self::STRING_CALLBACKS[$lc])) {
            throw new \LogicException(
                "String compare callback '{$name}' is not supported in this compiler build"
            );
        }

        $class = self::STRING_CALLBACKS[$lc];

        return new $class();
    }

    /** Resolve strcmp-family comparator from sort() flags (php-src php_array_sort). */
    public static function stringCompareForSortFlags(int $flags): Internal
    {
        return self::valueCompareForSortFlags($flags);
    }

    /** Resolve strcmp-family comparator for asort/arsort value operands (php-src php_array_sort). */
    public static function valueCompareForSortFlags(int $flags): Internal
    {
        $caseFlag = $flags & StdlibConstants::SORT_FLAG_CASE;
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;

        return match ($sortType) {
            StdlibConstants::SORT_NATURAL => self::resolveStringCallback(
                0 !== $caseFlag ? 'strnatcasecmp' : 'strnatcmp'
            ),
            StdlibConstants::SORT_STRING => self::resolveStringCallback(
                0 !== $caseFlag ? 'strcasecmp' : 'strcmp'
            ),
            StdlibConstants::SORT_LOCALE_STRING => self::resolveStringCallback('strcoll'),
            StdlibConstants::SORT_REGULAR,
            StdlibConstants::SORT_NUMERIC => self::resolveStringCallback(
                0 !== $caseFlag ? 'strcasecmp' : 'strcmp'
            ),
            default => self::resolveStringCallback(
                0 !== $caseFlag ? 'strcasecmp' : 'strcmp'
            ),
        };
    }

    /**
     * Parse optional sort_flags argument on ksort/asort family (php-src basic_functions.c).
     *
     * @throws \LogicException when flags are not an integer
     */
    public static function resolveFrameSortFlags(Frame $frame, string $function, int $argIndex = 1): int
    {
        return self::resolveFrameSortFlagsOperand(
            $frame->calledArgs[$argIndex]->resolveIndirect(),
            $function,
            $argIndex + 1,
            '$flags',
            false
        );
    }

    /**
     * sort()/rsort() flags (php-src basic_functions.stub.php — int $flags only; #23225, #28930).
     * Phantom Sorting enum retired — Zend never accepts enum flags.
     */
    public static function resolveSortFunctionFlags(Frame $frame, string $function): int
    {
        if (!isset($frame->calledArgs[1])) {
            return StdlibConstants::SORT_REGULAR;
        }

        return self::resolveFrameSortFlagsOperand(
            $frame->calledArgs[1]->resolveIndirect(),
            $function,
            2,
            '$flags',
            false
        );
    }

    /**
     * @throws \LogicException when $allowSortingEnum is false and operand is not int
     * @throws \TypeError when $allowSortingEnum is true and operand is not int (historical Sorting path)
     */
    public static function resolveFrameSortFlagsOperand(
        Variable $flagsArg,
        string $function,
        int $argNum,
        string $paramName,
        bool $allowSortingEnum
    ): int {
        if (Variable::TYPE_NULL === $flagsArg->type) {
            return StdlibConstants::SORT_REGULAR;
        }
        if ($allowSortingEnum) {
            $fromEnum = VmArraySort::trySortingOrderInt($flagsArg);
            if (null !== $fromEnum) {
                return $fromEnum;
            }
            if (EnumCaseSupport::isEnumCaseVariable($flagsArg)) {
                throw new \TypeError(sprintf(
                    '%s(): Argument #%d (%s) must be of type int, %s given',
                    $function,
                    $argNum,
                    $paramName,
                    EnumCaseSupport::typeNameForVariable($flagsArg)
                ));
            }
            if (Variable::TYPE_INTEGER !== $flagsArg->type) {
                throw new \TypeError(sprintf(
                    '%s(): Argument #%d (%s) must be of type int, %s given',
                    $function,
                    $argNum,
                    $paramName,
                    self::vmSortFlagsTypeName($flagsArg->type)
                ));
            }

            return $flagsArg->toInt();
        }
        if (Variable::TYPE_INTEGER !== $flagsArg->type) {
            throw new \LogicException($function.'() flags must be an integer in this compiler build');
        }

        return $flagsArg->toInt();
    }

    public static function vmSortFlagsTypeName(int $type): string
    {
        return match ($type) {
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            Variable::TYPE_OBJECT => 'object',
            default => 'mixed',
        };
    }

    /**
     * Resolve sort_flags at JIT/AOT compile time (constant name, integer literal, or CFG trace).
     *
     * @throws \LogicException when flags are not compile-time known
     */
    public static function resolveJitSortFlags(
        Context $context,
        JITVariable $flagsArg,
        string $function,
        ?Block $block = null,
        ?Operand $flagsOp = null
    ): int {
        $resolved = self::tryResolveJitSortFlags($context, $flagsArg);
        if (null === $resolved && null !== $block && null !== $flagsOp) {
            $resolved = self::tryResolveJitSortFlagsFromBlock($context, $block, $flagsOp);
        }
        if (null === $resolved) {
            if (JITVariable::TYPE_NATIVE_LONG === $flagsArg->type) {
                throw new \LogicException(
                    $function.'() flags must be a predefined constant in JIT/AOT in this compiler build'
                );
            }
            throw new \LogicException($function.'() flags must be an integer in this compiler build');
        }

        return $resolved;
    }

    public static function tryResolveJitSortFlags(Context $context, JITVariable $flagsArg): ?int
    {
        if (JITVariable::TYPE_NULL === $flagsArg->type || ($flagsArg->isNullConstant ?? false)) {
            return StdlibConstants::SORT_REGULAR;
        }
        $constName = $flagsArg->compileTimeConstantName ?? null;
        if (null !== $constName) {
            $lookup = strtolower($constName);
            if (isset(StdlibConstants::CORE_INT_BY_NAME[$lookup])) {
                return StdlibConstants::CORE_INT_BY_NAME[$lookup];
            }
            if (null !== $context->runtime->vmContext) {
                $phpVar = $context->runtime->vmContext->constantFetch($constName);
                if (null !== $phpVar && Variable::TYPE_INTEGER === $phpVar->type) {
                    return $phpVar->toInt();
                }
            }
        }
        if (JITVariable::TYPE_NATIVE_LONG === $flagsArg->type
            && JITVariable::KIND_VALUE === $flagsArg->kind
        ) {
            $lib = $context->llvm->lib;
            if (null !== $lib->LLVMIsAConstantInt($flagsArg->value->value)) {
                return (int) $lib->LLVMConstIntGetZExtValue($flagsArg->value->value);
            }
        }

        return null;
    }

    /** Resolve SORT_* flags from CFG when JIT operands are boxed (#9123). */
    public static function tryResolveJitSortFlagsFromBlock(Context $context, Block $block, Operand $flagsOp): ?int
    {
        $slot = self::operandSlot($block, $flagsOp);
        if (null === $slot) {
            return null;
        }

        return self::slotSortFlags($context, $block, $slot, []);
    }

    /**
     * @param array<int, true> $visited
     */
    private static function slotSortFlags(Context $context, Block $block, int $slot, array $visited): ?int
    {
        if (isset($visited[$slot])) {
            return null;
        }
        $visited[$slot] = true;

        if (isset($block->constants[$slot])) {
            $const = $block->constants[$slot];
            if (Variable::TYPE_INTEGER === $const->type) {
                return $const->toInt();
            }
        }

        foreach ($block->opCodes as $op) {
            if ($op->arg1 !== $slot) {
                continue;
            }
            if (OpCode::TYPE_CONST_FETCH === $op->type) {
                return self::sortFlagsFromConstFetch($context, $block, $op);
            }
            // Inline SORT_* | SORT_FLAG_CASE (and &/^) — peer pathinfo bitmask (#9278 / #29114).
            if (OpCode::TYPE_BITWISE_AND === $op->type
                || OpCode::TYPE_BITWISE_OR === $op->type
                || OpCode::TYPE_BITWISE_XOR === $op->type
            ) {
                $left = null !== $op->arg2 ? self::slotSortFlags($context, $block, $op->arg2, $visited) : null;
                $right = null !== $op->arg3 ? self::slotSortFlags($context, $block, $op->arg3, $visited) : null;
                if (null === $left || null === $right) {
                    return null;
                }

                return match ($op->type) {
                    OpCode::TYPE_BITWISE_AND => $left & $right,
                    OpCode::TYPE_BITWISE_OR => $left | $right,
                    OpCode::TYPE_BITWISE_XOR => $left ^ $right,
                    default => null,
                };
            }
        }

        return null;
    }

    private static function sortFlagsFromConstFetch(Context $context, Block $block, OpCode $op): ?int
    {
        $nameOp = null !== $op->arg3 ? $block->getOperand($op->arg3) : $block->getOperand($op->arg2);
        if (!$nameOp instanceof Operand\Literal) {
            return null;
        }
        $lookup = strtolower((string) $nameOp->value);
        if (isset(StdlibConstants::CORE_INT_BY_NAME[$lookup])) {
            return StdlibConstants::CORE_INT_BY_NAME[$lookup];
        }
        if (null === $context->runtime->vmContext) {
            return null;
        }
        $phpVar = $context->runtime->vmContext->constantFetch((string) $nameOp->value);
        if (null !== $phpVar && Variable::TYPE_INTEGER === $phpVar->type) {
            return $phpVar->toInt();
        }

        return null;
    }

    private static function operandSlot(Block $block, Operand $op): ?int
    {
        foreach ($block->opCodes as $opcode) {
            foreach ([$opcode->arg1, $opcode->arg2, $opcode->arg3] as $slot) {
                if (null === $slot) {
                    continue;
                }
                try {
                    if ($block->getOperand($slot) === $op) {
                        return $slot;
                    }
                } catch (\Throwable) {
                    continue;
                }
            }
        }

        return null;
    }

    /** Compare array keys for ksort/krsort with php-src sort_type dispatch. */
    public static function compareKeysForSort(Variable $a, Variable $b, int $flags): int
    {
        $caseFlag = $flags & StdlibConstants::SORT_FLAG_CASE;
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;

        if (StdlibConstants::SORT_NUMERIC === $sortType) {
            return self::compareNumericOperandsForSort($a, $b);
        }
        if (StdlibConstants::SORT_NATURAL === $sortType) {
            return self::invoke(
                self::resolveStringCallback(0 !== $caseFlag ? 'strnatcasecmp' : 'strnatcmp'),
                self::coerceForStringSort($a),
                self::coerceForStringSort($b)
            );
        }
        if (StdlibConstants::SORT_LOCALE_STRING === $sortType) {
            return self::invoke(
                self::resolveStringCallback('strcoll'),
                self::coerceForStringSort($a),
                self::coerceForStringSort($b)
            );
        }
        if (StdlibConstants::SORT_STRING === $sortType) {
            return self::invoke(
                self::resolveStringCallback(0 !== $caseFlag ? 'strcasecmp' : 'strcmp'),
                self::coerceForStringSort($a),
                self::coerceForStringSort($b)
            );
        }

        return self::compareRegularOperands($a, $b, 0 !== $caseFlag);
    }

    /** Compare array values for asort/arsort packed lists with SORT_NUMERIC (php-src). */
    public static function compareValuesForSortFlags(Variable $a, Variable $b, int $flags, bool $descending = false): int
    {
        $caseFlag = $flags & StdlibConstants::SORT_FLAG_CASE;
        $sortType = $flags & ~StdlibConstants::SORT_FLAG_CASE;
        if (StdlibConstants::SORT_NUMERIC === $sortType) {
            return self::compareNumericOperandsForSort($a, $b, $descending);
        }
        if (StdlibConstants::SORT_LOCALE_STRING === $sortType) {
            return self::invoke(
                self::resolveStringCallback('strcoll'),
                self::coerceForStringSort($a),
                self::coerceForStringSort($b)
            );
        }
        if (
            StdlibConstants::SORT_STRING === $sortType
            || StdlibConstants::SORT_NATURAL === $sortType
        ) {
            return self::invoke(
                self::valueCompareForSortFlags($flags),
                self::coerceForStringSort($a),
                self::coerceForStringSort($b)
            );
        }
        if (StdlibConstants::SORT_REGULAR === $sortType) {
            return self::compareRegularOperands($a, $b, 0 !== $caseFlag, $descending);
        }

        return self::compareValuesForSort($a, $b);
    }

    /**
     * php-src zend_compare for SORT_REGULAR — numeric strings compare numerically (#13028).
     */
    public static function compareRegularOperands(Variable $a, Variable $b, bool $caseInsensitive = false, bool $descending = false): int
    {
        $a = $a->resolveIndirect();
        $b = $b->resolveIndirect();
        if (Variable::TYPE_STRING === $a->type && Variable::TYPE_STRING === $b->type) {
            $as = $a->toString();
            $bs = $b->toString();
            if ('' !== $as && '' !== $bs && \is_numeric($as) && \is_numeric($bs)) {
                return self::compareNumericOperandsForSort($a, $b, $descending);
            }
            $cmp = $caseInsensitive ? strcasecmp($as, $bs) : strcmp($as, $bs);

            return $cmp < 0 ? -1 : ($cmp > 0 ? 1 : 0);
        }
        if (
            (Variable::TYPE_INTEGER === $a->type || Variable::TYPE_FLOAT === $a->type)
            && (Variable::TYPE_INTEGER === $b->type || Variable::TYPE_FLOAT === $b->type)
        ) {
            return self::compareNumericOperandsForSort($a, $b, $descending);
        }

        return Variable::compareSpaceship($a, $b);
    }

    /** php-src zend_compare numeric sort — non-numeric strings compare as 0. */
    private static function compareNumericOperandsForSort(Variable $a, Variable $b, bool $descending = false): int
    {
        return self::compareNumericScalarsForSort(
            self::numericSortScalar($a),
            self::numericSortScalar($b),
            $descending
        );
    }

    /**
     * php-src array sort NaN branch — NaN compares less than finite numbers (#10144, ext/standard/array.c).
     * Differs from {@see Variable::spaceshipNumeric()} (<=> always returns 1 when NaN is involved).
     * Descending sort keeps NaN slots stable (spaceship-style +1 when NaN is involved).
     *
     * @param int|float $left
     * @param int|float $right
     */
    public static function compareNumericScalarsForSort(int|float $left, int|float $right, bool $descending = false): int
    {
        if (\is_float($left) && \is_nan($left)) {
            if (\is_float($right) && \is_nan($right)) {
                return 0;
            }

            return $descending ? 1 : -1;
        }
        if (\is_float($right) && \is_nan($right)) {
            return 1;
        }
        if (\is_float($left) || \is_float($right)) {
            $lf = (float) $left;
            $rf = (float) $right;
            if ($lf < $rf) {
                return -1;
            }
            if ($lf > $rf) {
                return 1;
            }

            return 0;
        }

        return (int) $left <=> (int) $right;
    }

    private static function numericSortScalar(Variable $value): int|float
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_INTEGER === $value->type) {
            return $value->toInt();
        }
        if (Variable::TYPE_FLOAT === $value->type) {
            return $value->toFloat();
        }
        if (Variable::TYPE_STRING === $value->type) {
            $s = $value->toString();
            if ('' === $s || !\is_numeric($s)) {
                return 0;
            }

            return str_contains($s, '.') ? (float) $s : (int) $s;
        }
        if (Variable::TYPE_NULL === $value->type) {
            return 0;
        }
        if (Variable::TYPE_BOOLEAN === $value->type) {
            return $value->toBool() ? 1 : 0;
        }

        return 0;
    }

    public static function invoke(Internal $fn, Variable $a, Variable $b): int
    {
        if (self::isStringCompareCallback($fn)) {
            $a = self::coerceForStringSort($a);
            $b = self::coerceForStringSort($b);
        }
        $frame = new Frame($fn, null, null);
        $frame->calledArgs = [$a, $b];
        $out = new Variable();
        $frame->returnVar = $out;
        $fn->execute($frame);

        return $out->resolveIndirect()->toInt();
    }

    private static function isStringCompareCallback(Internal $fn): bool
    {
        $name = strtolower($fn->getName());

        return isset(self::STRING_CALLBACKS[$name]);
    }

    /** php-src php_array_keycompare / php_array_sort SORT_STRING — cast keys/values before strcmp. */
    private static function coerceForStringSort(Variable $operand): Variable
    {
        $resolved = $operand->resolveIndirect();
        if (Variable::TYPE_STRING === $resolved->type) {
            return $resolved;
        }
        $str = new Variable();
        $str->string($resolved->toString());

        return $str;
    }

    /**
     * Sort packed Variable list using {@see Variable::compareSpaceship()} (php-src zend_compare).
     *
     * @param list<Variable> $values
     */
    public static function sortVariableValuesBySpaceship(array &$values): void
    {
        $n = \count($values);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareValuesForSort($values[$j - 1], $values[$j]) > 0) {
                $tmp = $values[$j - 1];
                $values[$j - 1] = $values[$j];
                $values[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort packed Variable list descending via {@see compareValuesForSort()} (rsort/arsort enum arrays, #6150).
     *
     * @param list<Variable> $values
     */
    public static function sortVariableValuesBySpaceshipDesc(array &$values): void
    {
        $n = \count($values);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareValuesForSort($values[$j - 1], $values[$j]) < 0) {
                $tmp = $values[$j - 1];
                $values[$j - 1] = $values[$j];
                $values[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort packed Variable list in place (no PHP closures — AOT self-host spine safe).
     *
     * @param list<Variable> $values
     */
    /** Sort packed values ascending using php-src sort_type dispatch (#4076). */
    public static function sortVariableValuesWithFlags(array &$values, int $flags): void
    {
        $n = \count($values);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0) {
                $cmp = self::compareValuesForSortFlags($values[$j - 1], $values[$j], $flags);
                if ($cmp <= 0) {
                    break;
                }
                $tmp = $values[$j - 1];
                $values[$j - 1] = $values[$j];
                $values[$j] = $tmp;
                --$j;
            }
        }
    }

    /** Sort packed values descending using php-src sort_type dispatch (#4076). */
    public static function sortVariableValuesWithFlagsDesc(array &$values, int $flags): void
    {
        $n = \count($values);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0) {
                $cmp = self::compareValuesForSortFlags($values[$j - 1], $values[$j], $flags, true);
                if ($cmp >= 0) {
                    break;
                }
                $tmp = $values[$j - 1];
                $values[$j - 1] = $values[$j];
                $values[$j] = $tmp;
                --$j;
            }
        }
    }

    public static function sortVariableValues(array &$values, Internal $compare): void
    {
        $n = \count($values);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::invoke($compare, $values[$j - 1], $values[$j]) > 0) {
                $tmp = $values[$j - 1];
                $values[$j - 1] = $values[$j];
                $values[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort packed Variable list in place descending (arsort packed lists).
     *
     * @param list<Variable> $values
     */
    public static function sortVariableValuesDesc(array &$values, Internal $compare): void
    {
        $n = \count($values);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::invoke($compare, $values[$j - 1], $values[$j]) < 0) {
                $tmp = $values[$j - 1];
                $values[$j - 1] = $values[$j];
                $values[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] Variable pairs in place by value (no PHP closures — AOT self-host spine safe).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValue(array &$pairs, Internal $compare): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::invoke($compare, $pairs[$j - 1][1], $pairs[$j][1]) > 0) {
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] Variable pairs in place by value descending (arsort).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValueDesc(array &$pairs, Internal $compare): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::invoke($compare, $pairs[$j - 1][1], $pairs[$j][1]) < 0) {
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] pairs ascending by enum/object value spaceship (#5546, #6150).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValueSpaceship(array &$pairs): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareValuesForSort($pairs[$j - 1][1], $pairs[$j][1]) > 0) {
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] pairs descending by enum/object value spaceship (#6150).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValueSpaceshipDesc(array &$pairs): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareValuesForSort($pairs[$j - 1][1], $pairs[$j][1]) < 0) {
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] pairs in place by integer value descending (arsort subset).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValueIntDesc(array &$pairs): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0) {
                $a = $pairs[$j - 1][1]->resolveIndirect();
                $b = $pairs[$j][1]->resolveIndirect();
                if (Variable::TYPE_INTEGER !== $a->type || Variable::TYPE_INTEGER !== $b->type) {
                    throw new \LogicException(
                        'arsort() only supports homogeneous string or integer values in this compiler build'
                    );
                }
                if ($a->toInt() >= $b->toInt()) {
                    break;
                }
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] pairs in place by integer value ascending (asort subset).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValueInt(array &$pairs): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0) {
                $a = $pairs[$j - 1][1]->resolveIndirect();
                $b = $pairs[$j][1]->resolveIndirect();
                if (Variable::TYPE_INTEGER !== $a->type || Variable::TYPE_INTEGER !== $b->type) {
                    throw new \LogicException(
                        'asort() only supports homogeneous string or integer values in this compiler build'
                    );
                }
                if ($a->toInt() <= $b->toInt()) {
                    break;
                }
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] Variable pairs in place by key using a string builtin comparator (uksort subset).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByKeyWithCompare(array &$pairs, Internal $compare): void
    {
        self::sortKeyedPairsByKeyWithCompareOrdered($pairs, $compare, false);
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByKeyWithCompareDesc(array &$pairs, Internal $compare): void
    {
        self::sortKeyedPairsByKeyWithCompareOrdered($pairs, $compare, true);
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    private static function sortKeyedPairsByKeyWithCompareOrdered(
        array &$pairs,
        Internal $compare,
        bool $descending
    ): void {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0) {
                $cmp = self::invoke(
                    $compare,
                    self::coerceForStringSort($pairs[$j - 1][0]),
                    self::coerceForStringSort($pairs[$j][0])
                );
                if ($descending) {
                    if ($cmp >= 0) {
                        break;
                    }
                } elseif ($cmp <= 0) {
                    break;
                }
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] Variable pairs in place by key (no PHP closures — AOT self-host spine safe).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByKey(array &$pairs, int $flags = StdlibConstants::SORT_REGULAR): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareKeysForSort($pairs[$j - 1][0], $pairs[$j][0], $flags) > 0) {
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * Sort [key, value] Variable pairs in place by key descending (krsort).
     *
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByKeyDesc(array &$pairs, int $flags = StdlibConstants::SORT_REGULAR): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0 && self::compareKeysForSort($pairs[$j - 1][0], $pairs[$j][0], $flags) < 0) {
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    public static function compareKeys(Variable $a, Variable $b): int
    {
        $a = $a->resolveIndirect();
        $b = $b->resolveIndirect();
        if (Variable::TYPE_STRING === $a->type && Variable::TYPE_STRING === $b->type) {
            return self::compareRegularOperands($a, $b);
        }
        if (Variable::TYPE_INTEGER === $a->type && Variable::TYPE_INTEGER === $b->type) {
            return $a->toInt() <=> $b->toInt();
        }
        if (Variable::TYPE_INTEGER === $a->type && Variable::TYPE_STRING === $b->type) {
            return -1;
        }
        if (Variable::TYPE_STRING === $a->type && Variable::TYPE_INTEGER === $b->type) {
            return 1;
        }

        throw new \LogicException(
            'ksort() only supports string or integer keys in this compiler build'
        );
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValueWithFlags(array &$pairs, int $flags): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0) {
                $cmp = self::compareValuesForSortFlags($pairs[$j - 1][1], $pairs[$j][1], $flags);
                if ($cmp <= 0) {
                    break;
                }
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * @param list<array{0: Variable, 1: Variable}> $pairs
     */
    public static function sortKeyedPairsByValueWithFlagsDesc(array &$pairs, int $flags): void
    {
        $n = \count($pairs);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0) {
                $cmp = self::compareValuesForSortFlags($pairs[$j - 1][1], $pairs[$j][1], $flags, true);
                if ($cmp >= 0) {
                    break;
                }
                $tmp = $pairs[$j - 1];
                $pairs[$j - 1] = $pairs[$j];
                $pairs[$j] = $tmp;
                --$j;
            }
        }
    }

    /**
     * @param list<Variable> $values
     */
    public static function valuesAreEnumOrObjectOnly(array $values): bool
    {
        if ([] === $values) {
            return false;
        }
        foreach ($values as $value) {
            $resolved = $value->resolveIndirect();
            if (!EnumCaseSupport::isEnumCaseVariable($resolved) && Variable::TYPE_OBJECT !== $resolved->type) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<Variable> $values
     */
    public static function valuesShareScalarType(array $values, int $type): bool
    {
        foreach ($values as $value) {
            if ($type !== $value->resolveIndirect()->type) {
                return false;
            }
        }

        return true;
    }

    /** Compare packed array values for sort()/array_multisort() enum and object operands (#5624). */
    public static function comparePackedValuesForSort(Variable $left, Variable $right): int
    {
        return self::compareValuesForSort($left, $right);
    }

    private static function compareValuesForSort(Variable $left, Variable $right): int
    {
        if (EnumCaseSupport::isEnumCaseVariable($left) && EnumCaseSupport::isEnumCaseVariable($right)) {
            return EnumCaseSupport::compareEnumCasesForSort($left, $right);
        }

        return Variable::compareSpaceship($left, $right);
    }

    /**
     * @param list<Variable> $values
     */
    public static function assertHomogeneousEnumOrObjectValues(array $values, string $function): void
    {
        foreach ($values as $value) {
            $resolved = $value->resolveIndirect();
            if (EnumCaseSupport::isEnumCaseVariable($resolved)) {
                continue;
            }
            if (Variable::TYPE_OBJECT !== $resolved->type) {
                throw new \LogicException(
                    $function.' only supports homogeneous object arrays in this compiler build'
                );
            }
        }
    }
}
