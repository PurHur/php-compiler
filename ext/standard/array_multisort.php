<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Builtin\MultisortRuntime;
use PHPCompiler\JIT\Builtin\SortRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ReferencableCheck;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_multisort() — coupled multi-array sort with per-array SORT_* flags (#1212, #3532).
 *
 * php-src: ext/standard/array.c PHP_FUNCTION(array_multisort)
 */
final class array_multisort extends Internal
{
    public function __construct()
    {
        parent::__construct('array_multisort');
    }

    public function execute(Frame $frame): void
    {
        $entries = VmArraySort::parseMultisortEntries($frame->calledArgs);
        if (1 === \count($entries)) {
            self::executeSingleArray($frame, $entries[0]);

            return;
        }

        $length = null;
        $allValues = [];
        $allPairs = [];
        $allPacked = [];
        foreach ($entries as $entryIdx => $entry) {
            $ht = $entry['array']->resolveIndirect()->toArray();
            $count = $ht->getNumElements();
            if (null === $length) {
                $length = $count;
            } elseif ($count !== $length) {
                throw new \ValueError('Array sizes are inconsistent');
            }
            $isPacked = $ht->isPackedList();
            $allPacked[$entryIdx] = $isPacked;
            $values = [];
            if ($isPacked) {
                foreach ($ht->iterate(true) as $value) {
                    $copy = new Variable();
                    $copy->copyFrom($value);
                    $values[] = $copy;
                }
                $allPairs[$entryIdx] = null;
            } else {
                $pairs = [];
                foreach ($ht->iterateKeyed(true) as [$key, $value]) {
                    $keyCopy = new Variable();
                    $keyCopy->copyFrom($key);
                    $copy = new Variable();
                    $copy->copyFrom($value);
                    $pairs[] = [$keyCopy, $copy];
                    $values[] = $copy;
                }
                $allPairs[$entryIdx] = $pairs;
            }
            $allValues[$entryIdx] = $values;
        }

        if (null === $length || $length < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }

        $indices = range(0, $length - 1);
        self::sortIndicesByMultisort($indices, $allValues, $entries);
        $caller = self::callerFrame($frame);
        $skipCoupledWriteback = self::shouldSkipCoupledMultisortWriteback($entries, $caller);
        foreach ($entries as $entryIdx => $entry) {
            if ($skipCoupledWriteback) {
                continue;
            }
            if (!ReferencableCheck::isReferenceable($entry['array'], $caller)) {
                continue;
            }
            self::writeSortedArray(
                $entry['array'],
                $allValues[$entryIdx],
                $allPairs[$entryIdx],
                $allPacked[$entryIdx],
                $indices
            );
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        // php-src array|sort-flag TypeError — catchable under AOT try/catch (#27511).
        ExceptionBridge::ensureLinked($context);
        $entries = self::parseJitMultisortEntries($context, $args);
        foreach ($entries as $entry) {
            JitArrayElem::requireMultisortArrayArg($context, $entry['array'], $entry['argIndex']);
        }
        $sortable = [];
        foreach ($entries as $entry) {
            $arg = $entry['array'];
            if ($arg->type & JITVariable::IS_NATIVE_ARRAY
                || JITVariable::TYPE_HASHTABLE === $arg->type
                || JITVariable::TYPE_VALUE === $arg->type
            ) {
                $sortable[] = $entry;
            }
        }
        if ([] === $sortable) {
            // Static non-array types already raised above; poison bool return for SSA.
            return $context->getTypeFromString('int1')->constInt(1, false);
        }
        if (1 === \count($sortable)) {
            $entry = $sortable[0];
            if (StdlibConstants::SORT_DESC === $entry['sortOrder']) {
                SortRuntime::sortPackedReverse($context, $entry['array']);
            } else {
                SortRuntime::sortPacked($context, $entry['array']);
            }

            return $context->getTypeFromString('int1')->constInt(1, false);
        }

        $arrays = array_column($sortable, 'array');
        $descending = StdlibConstants::SORT_DESC === $sortable[0]['sortOrder'];
        MultisortRuntime::multisortPacked($context, $arrays, $descending);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    /**
     * php-src php_array_multisort: one array sorts that array in place (ext/standard/array.c, #4945).
     *
     * @param array{array: Variable, sortOrder: int, sortType: int} $entry
     */
    private static function executeSingleArray(Frame $frame, array $entry): void
    {
        $array = $entry['array'];
        $resolved = $array->resolveIndirect();
        $ht = $resolved->toArray();
        $length = $ht->getNumElements();
        if ($length < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $values = [];
        $pairs = [];
        $isPacked = $ht->isPackedList();
        if ($isPacked) {
            foreach ($ht->iterate(true) as $value) {
                $copy = new Variable();
                $copy->copyFrom($value);
                $values[] = $copy;
            }
        } else {
            foreach ($ht->iterateKeyed(true) as [$key, $value]) {
                $keyCopy = new Variable();
                $keyCopy->copyFrom($key);
                $copy = new Variable();
                $copy->copyFrom($value);
                $pairs[] = [$keyCopy, $copy];
                $values[] = $copy;
            }
        }
        $indices = range(0, $length - 1);
        self::sortIndicesByMultisort($indices, [0 => $values], [$entry]);
        self::writeSortedArray($array, $values, $pairs, $isPacked, $indices);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    /**
     * php-src skips coupled companion writeback when the primary array operand is an inline temp (#15151).
     *
     * @param list<array{array: Variable, sortOrder: int, sortType: int}> $entries
     */
    private static function callerFrame(Frame $handlerFrame): Frame
    {
        return $handlerFrame->parent ?? $handlerFrame;
    }

    /** True when the primary multisort array operand is an inline literal temp (#15151). */
    private static function isInlinePrimaryMultisortArray(Variable $arg, Frame $caller): bool
    {
        if (ReferencableCheck::isEphemeralArrayArg($arg, $caller)) {
            return true;
        }
        if (!$arg->isIndirect()) {
            return false;
        }
        $target = $arg->byRefTarget();
        foreach ($caller->scope as $slot => $scoped) {
            if ($scoped === $arg && $scoped !== $target
                && null !== $caller->block
                && $caller->block->isNamedVariableSlot((int) $slot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-src skips coupled writeback when inline primary storage aliases a writable companion (#15151).
     *
     * @param list<array{array: Variable, sortOrder: int, sortType: int}> $entries
     */
    private static function shouldSkipCoupledMultisortWriteback(array $entries, Frame $caller): bool
    {
        if (\count($entries) < 2) {
            return false;
        }
        if (!ReferencableCheck::isReferenceable($entries[1]['array'], $caller)) {
            return false;
        }
        if (!self::isInlinePrimaryMultisortArray($entries[0]['array'], $caller)) {
            return false;
        }
        $primaryInner = $entries[0]['array']->byRefTarget();
        $companionInner = $entries[1]['array']->byRefTarget();
        $primaryHt = $primaryInner->resolveIndirect()->toArray();
        $companionHt = $companionInner->resolveIndirect()->toArray();

        return $primaryInner === $companionInner || $primaryHt === $companionHt;
    }

    /**
     * Write sorted values back — php-src php_array_multisort reindexes int keys to 0..n-1 (#13449).
     *
     * @param list<Variable>                             $values
     * @param list<array{0: Variable, 1: Variable}>|null $pairs
     * @param list<int>                                  $indices
     */
    private static function writeSortedArray(
        Variable $array,
        array $values,
        ?array $pairs,
        bool $isPacked,
        array $indices
    ): void {
        $array->separateArrayForWrite();
        $target = $array->resolveIndirect()->toArray();
        if ($isPacked) {
            $reordered = [];
            foreach ($indices as $idx) {
                $reordered[] = $values[$idx];
            }
            $target->replacePackedValues($reordered);

            return;
        }
        $reorderedPairs = [];
        $numericNext = 0;
        foreach ($indices as $idx) {
            [$key, $value] = $pairs[$idx];
            $keyResolved = $key->resolveIndirect();
            if (Variable::TYPE_INTEGER === $keyResolved->type) {
                $newKey = new Variable();
                $newKey->int($numericNext++);
                $reorderedPairs[] = [$newKey, $value];
            } else {
                $reorderedPairs[] = [$key, $value];
            }
        }
        $target->reorderKeyedPairs($reorderedPairs);
    }

    /**
     * @param list<int>                                           $indices
     * @param array<int, list<Variable>>                          $allValues
     * @param list<array{array: Variable, sortOrder: int, sortType: int}> $entries
     */
    private static function sortIndicesByMultisort(
        array &$indices,
        array $allValues,
        array $entries
    ): void {
        $n = \count($indices);
        for ($i = 1; $i < $n; ++$i) {
            $j = $i;
            while ($j > 0) {
                $cmp = self::compareMultisortIndices($allValues, $entries, $indices[$j - 1], $indices[$j]);
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

    /**
     * @param array<int, list<Variable>>                          $allValues
     * @param list<array{array: Variable, sortOrder: int, sortType: int}> $entries
     */
    private static function compareMultisortIndices(
        array $allValues,
        array $entries,
        int $idxA,
        int $idxB
    ): int {
        foreach ($entries as $entryIdx => $entry) {
            $cmp = self::compareMultisortValues(
                $allValues[$entryIdx][$idxA],
                $allValues[$entryIdx][$idxB],
                $entry['sortOrder'],
                $entry['sortType']
            );
            if (0 !== $cmp) {
                return $cmp;
            }
        }

        return $idxA <=> $idxB;
    }

    private static function compareMultisortValues(
        Variable $a,
        Variable $b,
        int $sortOrder,
        int $sortType
    ): int {
        $ra = $a->resolveIndirect();
        $rb = $b->resolveIndirect();
        if (EnumCaseSupport::isEnumCaseVariable($ra)
            || EnumCaseSupport::isEnumCaseVariable($rb)
            || Variable::TYPE_OBJECT === $ra->type
            || Variable::TYPE_OBJECT === $rb->type) {
            VmInternalCompare::assertHomogeneousEnumOrObjectValues([$a, $b], 'array_multisort()');
            $cmp = VmInternalCompare::comparePackedValuesForSort($a, $b);
        } else {
            $cmp = VmInternalCompare::compareValuesForSortFlags($a, $b, $sortType);
        }
        if (StdlibConstants::SORT_DESC === $sortOrder) {
            $cmp = -$cmp;
        }

        return $cmp;
    }

    /**
     * @param list<JITVariable> $args
     *
     * @return list<array{array: JITVariable, sortOrder: int, sortType: int, argIndex: int}>
     */
    private static function parseJitMultisortEntries(Context $context, array $args): array
    {
        $argc = \count($args);
        if ($argc < 1) {
            throw new \ArgumentCountError('array_multisort() expects at least 1 argument, 0 given');
        }

        $entries = [];
        $sortOrder = StdlibConstants::SORT_ASC;
        $sortType = StdlibConstants::SORT_REGULAR;
        $parseOrder = true;
        $parseType = true;

        for ($i = 0; $i < $argc; ++$i) {
            $arg = $args[$i];
            if (self::isJitArrayArg($arg)) {
                if ($i > 0 && \count($entries) > 0) {
                    $last = \count($entries) - 1;
                    $entries[$last]['sortOrder'] = $sortOrder;
                    $entries[$last]['sortType'] = $sortType;
                }
                $entries[] = [
                    'array' => $arg,
                    'sortOrder' => StdlibConstants::SORT_ASC,
                    'sortType' => StdlibConstants::SORT_REGULAR,
                    'argIndex' => $i,
                ];
                $sortOrder = StdlibConstants::SORT_ASC;
                $sortType = StdlibConstants::SORT_REGULAR;
                $parseOrder = true;
                $parseType = true;
                continue;
            }

            $flag = self::tryResolveJitMultisortFlag($context, $arg);
            if (null === $flag) {
                // Defer to ExceptionBridge so AOT try/catch can catch (#27511).
                // PHP throw here aborts compile of `try { array_multisort(null); }`.
                if ($i > 0 && \count($entries) > 0) {
                    $last = \count($entries) - 1;
                    $entries[$last]['sortOrder'] = $sortOrder;
                    $entries[$last]['sortType'] = $sortType;
                }
                $entries[] = [
                    'array' => $arg,
                    'sortOrder' => StdlibConstants::SORT_ASC,
                    'sortType' => StdlibConstants::SORT_REGULAR,
                    'argIndex' => $i,
                ];
                break;
            }

            $masked = $flag & ~StdlibConstants::SORT_FLAG_CASE;
            if (StdlibConstants::SORT_ASC === $masked || StdlibConstants::SORT_DESC === $masked) {
                if (!$parseOrder) {
                    throw new \TypeError(VmArraySort::multisortOperandTypeError(
                        $i,
                        ' that has not already been specified'
                    ));
                }
                $sortOrder = $masked;
                $parseOrder = false;
                continue;
            }

            if (!$parseType) {
                throw new \TypeError(VmArraySort::multisortOperandTypeError(
                    $i,
                    ' that has not already been specified'
                ));
            }
            $sortType = $flag;
            $parseType = false;
        }

        if (\count($entries) < 1) {
            throw new \LogicException(
                'array_multisort() requires at least one array argument in this compiler build'
            );
        }

        $last = \count($entries) - 1;
        $entries[$last]['sortOrder'] = $sortOrder;
        $entries[$last]['sortType'] = $sortType;

        return $entries;
    }

    private static function isJitArrayArg(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_HASHTABLE === ($arg->type & ~JITVariable::IS_NATIVE_ARRAY)
            || ArrayBuiltinHelper::isNativeArray($arg->type)) {
            return true;
        }

        return JITVariable::TYPE_VALUE === $arg->type;
    }

    private static function tryResolveJitMultisortFlag(Context $context, JITVariable $arg): ?int
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
