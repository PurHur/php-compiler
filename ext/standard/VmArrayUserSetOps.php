<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * User-comparator array diff/intersect builtins (php-src ext/standard/array.c; issue #5644).
 */
final class VmArrayUserSetOps
{
    public static function udiff(Frame $frame): void
    {
        self::runValueOp($frame, 'array_udiff', false);
    }

    public static function uintersect(Frame $frame): void
    {
        self::runValueOp($frame, 'array_uintersect', true);
    }

    public static function udiffAssoc(Frame $frame): void
    {
        self::runKeyValueOp($frame, 'array_udiff_assoc', false, false);
    }

    public static function uintersectAssoc(Frame $frame): void
    {
        self::runKeyValueOp($frame, 'array_uintersect_assoc', true, false);
    }

    public static function udiffUassoc(Frame $frame): void
    {
        self::runKeyValueOp($frame, 'array_udiff_uassoc', false, true);
    }

    public static function uintersectUassoc(Frame $frame): void
    {
        self::runKeyValueOp($frame, 'array_uintersect_uassoc', true, true);
    }

    public static function diffUassoc(Frame $frame): void
    {
        self::runDiffUassoc($frame);
    }

    public static function intersectUassoc(Frame $frame): void
    {
        self::runIntersectUassoc($frame);
    }

    public static function diffUkey(Frame $frame): void
    {
        self::runDiffUkey($frame);
    }

    public static function intersectUkey(Frame $frame): void
    {
        self::runIntersectUkey($frame);
    }

    private static function runValueOp(Frame $frame, string $fn, bool $intersect): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                $fn.'() expects at least 3 arguments, '.$argc.' given'
            );
        }
        $dataCompare = self::resolveCompareCallback($frame, $frame->calledArgs[$argc - 1], $fn, $argc);
        $first = VmArray::requireArrayParam($frame->calledArgs[0], $fn, 1, 'array');
        $others = self::collectOtherArrays($frame, $fn, 1, $argc - 1);
        if (null === $frame->returnVar) {
            return;
        }
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            $present = $intersect
                ? self::valueInAllOthers($value, $others, $dataCompare)
                : self::valueInAnyOther($value, $others, $dataCompare);
            if ($intersect ? !$present : $present) {
                continue;
            }
            self::appendToOutput($out, $key, $value);
        }
        $frame->returnVar->array($out);
    }

    private static function runKeyValueOp(
        Frame $frame,
        string $fn,
        bool $intersect,
        bool $dualCompare
    ): void {
        $argc = \count($frame->calledArgs);
        $minArgs = $dualCompare ? 4 : 3;
        if ($argc < $minArgs) {
            throw new \ArgumentCountError(
                $fn.'() expects at least '.$minArgs.' arguments, '.$argc.' given'
            );
        }
        $keyCompare = self::resolveCompareCallback(
            $frame,
            $frame->calledArgs[$argc - 1],
            $fn,
            $argc
        );
        $dataCompare = $dualCompare
            ? self::resolveCompareCallback($frame, $frame->calledArgs[$argc - 2], $fn, $argc - 1)
            : null;
        $arrayEnd = $dualCompare ? $argc - 2 : $argc - 1;
        $first = VmArray::requireArrayParam($frame->calledArgs[0], $fn, 1, 'array');
        $others = self::collectOtherArrays($frame, $fn, 1, $arrayEnd);
        if (null === $frame->returnVar) {
            return;
        }
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            $present = $intersect
                ? self::pairInAllOthers($key, $value, $others, $keyCompare, $dataCompare)
                : self::pairInAnyOther($key, $value, $others, $keyCompare, $dataCompare);
            if ($intersect ? !$present : $present) {
                continue;
            }
            self::appendToOutput($out, $key, $value);
        }
        $frame->returnVar->array($out);
    }

    private static function runDiffUassoc(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'array_diff_uassoc() expects at least 3 arguments, '.$argc.' given'
            );
        }
        $dataCompare = self::resolveCompareCallback($frame, $frame->calledArgs[$argc - 1], 'array_diff_uassoc', $argc);
        $first = VmArray::requireArrayParam($frame->calledArgs[0], 'array_diff_uassoc', 1, 'array');
        $others = self::collectOtherArrays($frame, 'array_diff_uassoc', 1, $argc - 1);
        if (null === $frame->returnVar) {
            return;
        }
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            if (self::exactPairInAnyOther($key, $value, $others, $dataCompare)) {
                continue;
            }
            self::appendToOutput($out, $key, $value);
        }
        $frame->returnVar->array($out);
    }

    private static function runIntersectUassoc(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'array_intersect_uassoc() expects at least 3 arguments, '.$argc.' given'
            );
        }
        $dataCompare = self::resolveCompareCallback(
            $frame,
            $frame->calledArgs[$argc - 1],
            'array_intersect_uassoc',
            $argc
        );
        $first = VmArray::requireArrayParam($frame->calledArgs[0], 'array_intersect_uassoc', 1, 'array');
        $others = self::collectOtherArrays($frame, 'array_intersect_uassoc', 1, $argc - 1);
        if (null === $frame->returnVar) {
            return;
        }
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            if (!self::exactPairInAllOthers($key, $value, $others, $dataCompare)) {
                continue;
            }
            self::appendToOutput($out, $key, $value);
        }
        $frame->returnVar->array($out);
    }

    private static function runDiffUkey(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'array_diff_ukey() expects at least 3 arguments, '.$argc.' given'
            );
        }
        $keyCompare = self::resolveCompareCallback($frame, $frame->calledArgs[$argc - 1], 'array_diff_ukey', $argc);
        $first = VmArray::requireArrayParam($frame->calledArgs[0], 'array_diff_ukey', 1, 'array');
        $others = self::collectOtherArrays($frame, 'array_diff_ukey', 1, $argc - 1);
        if (null === $frame->returnVar) {
            return;
        }
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            if (self::keyInAnyOther($key, $others, $keyCompare)) {
                continue;
            }
            self::appendToOutput($out, $key, $value);
        }
        $frame->returnVar->array($out);
    }

    private static function runIntersectUkey(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'array_intersect_ukey() expects at least 3 arguments, '.$argc.' given'
            );
        }
        $keyCompare = self::resolveCompareCallback(
            $frame,
            $frame->calledArgs[$argc - 1],
            'array_intersect_ukey',
            $argc
        );
        $first = VmArray::requireArrayParam($frame->calledArgs[0], 'array_intersect_ukey', 1, 'array');
        $others = self::collectOtherArrays($frame, 'array_intersect_ukey', 1, $argc - 1);
        if (null === $frame->returnVar) {
            return;
        }
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            if (!self::keyInAllOthers($key, $others, $keyCompare)) {
                continue;
            }
            self::appendToOutput($out, $key, $value);
        }
        $frame->returnVar->array($out);
    }

    /**
     * @return callable
     */
    private static function resolveCompareCallback(
        Frame $frame,
        Variable $callback,
        string $fn,
        int $argNum
    ): callable {
        $callback = $callback->resolveIndirect();
        VmArraySortCallback::requireCallback($callback, $fn, $argNum, null);
        if (VmClosureCall::isClosure($callback)) {
            if (null === $frame->vmContext) {
                throw new \LogicException($fn.'() requires VM context in this compiler build');
            }
            $closure = VmClosureCall::resolve($callback);
            $ctx = $frame->vmContext;

            return static fn (Variable $a, Variable $b): int => VmClosureCall::invokeTwo($ctx, $closure, $a, $b);
        }
        if (Variable::TYPE_STRING === $callback->type) {
            $name = $callback->toString();
            if (UsortCallbackPolicy::isVmSupportedName($name)) {
                $compare = VmInternalCompare::resolveStringCallback($name);

                return static fn (Variable $a, Variable $b): int => VmInternalCompare::invoke($compare, $a, $b);
            }
        }
        throw new \LogicException(
            $fn.'() callback must be a closure or strcmp/strcasecmp in this compiler build'
        );
    }

    /**
     * @return list<HashTable>
     */
    private static function collectOtherArrays(Frame $frame, string $fn, int $start, int $end): array
    {
        $others = [];
        for ($i = $start; $i < $end; ++$i) {
            $others[] = VmArray::requireArrayParam($frame->calledArgs[$i], $fn, $i + 1, 'array');
        }

        return $others;
    }

    /**
     * @param list<HashTable> $others
     * @param callable $compare
     */
    private static function valueInAnyOther(Variable $needle, array $others, callable $compare): bool
    {
        $needle = $needle->resolveIndirect();
        foreach ($others as $haystack) {
            foreach ($haystack->iterate(true) as $value) {
                if (0 === $compare($needle, $value->resolveIndirect())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<HashTable> $others
     * @param callable $compare
     */
    private static function valueInAllOthers(Variable $needle, array $others, callable $compare): bool
    {
        foreach ($others as $haystack) {
            if (!self::valueInAnyOther($needle, [$haystack], $compare)) {
                return false;
            }
        }

        return [] !== $others;
    }

    /**
     * @param list<HashTable> $others
     * @param callable $keyCompare
     * @param callable|null $dataCompare
     */
    private static function pairInAnyOther(
        Variable $key,
        Variable $value,
        array $others,
        callable $keyCompare,
        ?callable $dataCompare
    ): bool {
        foreach ($others as $haystack) {
            foreach ($haystack->iterateKeyed(true) as [$otherKey, $otherValue]) {
                if (0 !== $keyCompare($key, $otherKey)) {
                    continue;
                }
                if (null !== $dataCompare) {
                    if (0 === $dataCompare($value, $otherValue)) {
                        return true;
                    }
                    continue;
                }
                if ($value->resolveIndirect()->identicalTo($otherValue->resolveIndirect())) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<HashTable> $others
     * @param callable $keyCompare
     * @param callable|null $dataCompare
     */
    private static function pairInAllOthers(
        Variable $key,
        Variable $value,
        array $others,
        callable $keyCompare,
        ?callable $dataCompare
    ): bool {
        foreach ($others as $haystack) {
            if (!self::pairInAnyOther($key, $value, [$haystack], $keyCompare, $dataCompare)) {
                return false;
            }
        }

        return [] !== $others;
    }

    /**
     * @param list<HashTable> $others
     * @param callable $dataCompare
     */
    private static function exactPairInAnyOther(
        Variable $key,
        Variable $value,
        array $others,
        callable $dataCompare
    ): bool {
        foreach ($others as $haystack) {
            $otherValue = self::valueAtExactKey($haystack, $key);
            if (null === $otherValue) {
                continue;
            }
            if (0 === $dataCompare($value, $otherValue)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param list<HashTable> $others
     * @param callable $dataCompare
     */
    private static function exactPairInAllOthers(
        Variable $key,
        Variable $value,
        array $others,
        callable $dataCompare
    ): bool {
        foreach ($others as $haystack) {
            $otherValue = self::valueAtExactKey($haystack, $key);
            if (null === $otherValue) {
                return false;
            }
            if (0 !== $dataCompare($value, $otherValue)) {
                return false;
            }
        }

        return [] !== $others;
    }

    /**
     * @param list<HashTable> $others
     * @param callable $keyCompare
     */
    private static function keyInAnyOther(Variable $key, array $others, callable $keyCompare): bool
    {
        foreach ($others as $haystack) {
            foreach ($haystack->iterateKeyed(true) as [$otherKey, $_]) {
                if (0 === $keyCompare($key, $otherKey)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<HashTable> $others
     * @param callable $keyCompare
     */
    private static function keyInAllOthers(Variable $key, array $others, callable $keyCompare): bool
    {
        foreach ($others as $haystack) {
            if (!self::keyInAnyOther($key, [$haystack], $keyCompare)) {
                return false;
            }
        }

        return [] !== $others;
    }

    private static function valueAtExactKey(HashTable $table, Variable $key): ?Variable
    {
        $key = $key->resolveIndirect();
        if (Variable::TYPE_INTEGER === $key->type) {
            return $table->findIndex($key->toInt());
        }
        if (Variable::TYPE_FLOAT === $key->type) {
            return $table->findIndex($key->toInt());
        }
        if (Variable::TYPE_STRING === $key->type) {
            return $table->find($key->toString());
        }

        return null;
    }

    private static function appendToOutput(HashTable $out, Variable $key, Variable $value): void
    {
        $stored = new Variable();
        $stored->copyFrom($value);
        $key = $key->resolveIndirect();
        if (Variable::TYPE_INTEGER === $key->type) {
            $out->addIndex($key->toInt(), $stored);
        } else {
            $out->add($key->toString(), $stored);
        }
    }
}
