<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * JIT/AOT GC object registry for compiled embed modules (#9541, php-in-PHP).
 *
 * Replaces LLVM globals phpc_gc_objects[] / phpc_gc_prop_counts[] on embed JIT.
 * Standalone AOT keeps registry in {@see \PHPCompiler\JIT\Builtin\GcCollectCyclesStandaloneLlvm}.
 * php-src: Zend/zend_gc.c — gc_root_buffer / object tracking
 */
final class GcCollectCyclesRegistryJitHelper
{
    public const MAX_OBJECTS = 65536;

    private static int $count = 0;

    /** @var array<int, int> opaque object pointers */
    private static array $objectPtr = [];

    /** @var array<int, int> */
    private static array $propCount = [];

    /** @var array<int, bool> */
    private static array $destructInvoked = [];

    public static function resetForTest(): void
    {
        self::$count = 0;
        self::$objectPtr = [];
        self::$propCount = [];
        self::$destructInvoked = [];
    }

    public static function count(): int
    {
        return self::$count;
    }

    /** @internal LLVM register bridge */
    public static function appendObject(int $objPtr, int $propCount): void
    {
        if ($objPtr <= 0) {
            return;
        }
        if (self::$count >= self::MAX_OBJECTS) {
            return;
        }
        if (self::indexOf($objPtr) >= 0) {
            return;
        }
        $idx = self::$count;
        self::$objectPtr[$idx] = $objPtr;
        self::$propCount[$idx] = $propCount > 0 ? $propCount : 0;
        self::$destructInvoked[$idx] = false;
        self::$count = self::$count + 1;
    }

    /** @internal LLVM unregister bridge */
    public static function removeObject(int $objPtr): void
    {
        if ($objPtr <= 0) {
            return;
        }
        $idx = self::indexOf($objPtr);
        if ($idx < 0) {
            return;
        }
        $last = self::$count - 1;
        if ($idx < $last) {
            self::$objectPtr[$idx] = self::$objectPtr[$last];
            self::$propCount[$idx] = self::$propCount[$last];
            self::$destructInvoked[$idx] = self::$destructInvoked[$last];
            unset(self::$objectPtr[$last], self::$propCount[$last], self::$destructInvoked[$last]);
        } else {
            unset(self::$objectPtr[$idx], self::$propCount[$idx], self::$destructInvoked[$idx]);
        }
        self::$count = self::$count - 1;
    }

    public static function indexOf(int $objPtr): int
    {
        if ($objPtr <= 0) {
            return -1;
        }
        for ($i = 0; $i < self::$count; ++$i) {
            if (isset(self::$objectPtr[$i]) && self::$objectPtr[$i] === $objPtr) {
                return $i;
            }
        }

        return -1;
    }

    public static function objectPtr(int $index): int
    {
        if ($index < 0 || $index >= self::$count) {
            return 0;
        }

        return self::$objectPtr[$index] ?? 0;
    }

    public static function propCount(int $index): int
    {
        if ($index < 0 || $index >= self::$count) {
            return 0;
        }

        return self::$propCount[$index] ?? 0;
    }

    public static function isDestructInvoked(int $index): bool
    {
        if ($index < 0 || $index >= self::$count) {
            return false;
        }

        return self::$destructInvoked[$index] ?? false;
    }

    public static function markDestructInvoked(int $index): void
    {
        if ($index < 0 || $index >= self::$count) {
            return;
        }
        self::$destructInvoked[$index] = true;
    }
}
