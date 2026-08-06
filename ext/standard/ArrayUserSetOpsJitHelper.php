<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

/**
 * array_udiff()/array_uintersect()/array_diff_ukey()/array_intersect_ukey() NestedJIT helpers (#18515).
 *
 * Thin standalone AOT uses pure LLVM {@see \PHPCompiler\JIT\ArrayUserSetOpsValueLlvm} /
 * {@see \PHPCompiler\JIT\ArrayUserSetOpsKeyLlvm} instead — NestedJIT of these methods aborts
 * (#26976 / #27533). Host/VM execute stays on {@see VmArrayUserSetOps}.
 *
 * php-src: ext/standard/array.c — php_array_udiff(), php_array_uintersect(), php_array_diff_ukey(), php_array_intersect_ukey()
 */
final class ArrayUserSetOpsJitHelper
{
    public static function diffByValueWithClosure(
        HashTable $first,
        HashTable $othersPacked,
        Variable $closure
    ): HashTable {
        return self::filterFirstByValueCompare($first, self::unpackOthers($othersPacked), false, $closure);
    }

    public static function intersectByValueWithClosure(
        HashTable $first,
        HashTable $othersPacked,
        Variable $closure
    ): HashTable {
        return self::filterFirstByValueCompare($first, self::unpackOthers($othersPacked), true, $closure);
    }

    public static function diffByKeyWithClosure(
        HashTable $first,
        HashTable $othersPacked,
        Variable $closure
    ): HashTable {
        return self::filterFirstByKeyCompare($first, self::unpackOthers($othersPacked), false, $closure);
    }

    public static function intersectByKeyWithClosure(
        HashTable $first,
        HashTable $othersPacked,
        Variable $closure
    ): HashTable {
        return self::filterFirstByKeyCompare($first, self::unpackOthers($othersPacked), true, $closure);
    }

    /**
     * @return list<HashTable>
     */
    private static function unpackOthers(HashTable $packed): array
    {
        $others = [];
        foreach ($packed->iterate(true) as $value) {
            $others[] = $value->resolveIndirect()->toArray();
        }

        return $others;
    }

    /**
     * @param list<HashTable> $others
     */
    private static function filterFirstByValueCompare(
        HashTable $first,
        array $others,
        bool $intersect,
        Variable $closure
    ): HashTable {
        $compare = self::closureCompare($closure);
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            $present = $intersect
                ? self::valueInAllOthers($value, $others, $compare)
                : self::valueInAnyOther($value, $others, $compare);
            if ($intersect ? !$present : $present) {
                continue;
            }
            self::appendToOutput($out, $key, $value);
        }

        return $out;
    }

    /**
     * @param list<HashTable> $others
     */
    private static function filterFirstByKeyCompare(
        HashTable $first,
        array $others,
        bool $intersect,
        Variable $closure
    ): HashTable {
        $compare = self::closureCompare($closure);
        $out = new HashTable();
        foreach ($first->iterateKeyed(true) as [$key, $value]) {
            $present = $intersect
                ? self::keyInAllOthers($key, $others, $compare)
                : self::keyInAnyOther($key, $others, $compare);
            if ($intersect ? !$present : $present) {
                continue;
            }
            self::appendToOutput($out, $key, $value);
        }

        return $out;
    }

    /**
     * @return callable(Variable, Variable): int
     */
    private static function closureCompare(Variable $closure): callable
    {
        $ctx = Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'ArrayUserSetOpsJitHelper requires an active VM context in this compiler build'
            );
        }
        $closureState = VmClosureCall::resolve($closure);

        return static fn (Variable $a, Variable $b): int => VmClosureCall::invokeTwoForUserCompare(
            $ctx,
            $closureState,
            $a,
            $b
        );
    }

    /**
     * @param list<HashTable> $others
     * @param callable(Variable, Variable): int $compare
     */
    private static function valueInAnyOther(Variable $needle, array $others, callable $compare): bool
    {
        $needle = $needle->resolveIndirect();
        foreach ($others as $haystack) {
            foreach ($haystack->iterate(true) as $value) {
                if (self::compareResultIsZero($compare($needle, $value->resolveIndirect()))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<HashTable> $others
     * @param callable(Variable, Variable): int $compare
     */
    private static function valueInAllOthers(Variable $needle, array $others, callable $compare): bool
    {
        foreach ($others as $haystack) {
            if (!self::valueInAnyOther($needle, [$haystack], $compare)) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param list<HashTable> $others
     * @param callable(Variable, Variable): int $compare
     */
    private static function keyInAnyOther(Variable $key, array $others, callable $compare): bool
    {
        foreach ($others as $haystack) {
            foreach ($haystack->iterateKeyed(true) as [$otherKey, $_]) {
                if (self::compareResultIsZero($compare($key, $otherKey))) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * @param list<HashTable> $others
     * @param callable(Variable, Variable): int $compare
     */
    private static function keyInAllOthers(Variable $key, array $others, callable $compare): bool
    {
        foreach ($others as $haystack) {
            if (!self::keyInAnyOther($key, [$haystack], $compare)) {
                return false;
            }
        }

        return true;
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

    private static function compareResultIsZero(mixed $result): bool
    {
        return 0 == $result;
    }
}
