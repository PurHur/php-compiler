<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * WeakMap / WeakReference registry for compiled JIT/AOT modules (#9191, php-in-PHP).
 *
 * Slot-table ABI mirrors legacy WeakRefRegistryRuntime LLVM globals. VM SSOT remains
 * {@see \PHPCompiler\VM\WeakRefRegistry} for interpreted code.
 * php-src: Zend/zend_weakrefs.c
 *
 * Guards and scan loops live in {@see \PHPCompiler\JIT\Builtin\WeakRefRegistryRuntime} LLVM
 * bridges — this file only exposes append/accessors safe for nested JIT (#11437).
 */
final class WeakRefRegistryJitHelper
{
    public const MAX_REFS = 4096;

    public const MAX_MAPS = 4096;

    private const MAP_KEY_BYTES = 40;

    private static int $refCount = 0;

    /** @var array<int, int> */
    private static array $refTargetPtr = [];

    /** @var array<int, int> */
    private static array $refSlotPtr = [];

    private static int $mapCount = 0;

    /** @var array<int, int> */
    private static array $mapTargetPtr = [];

    /** @var array<int, int> */
    private static array $mapHtPtr = [];

    /** @var array<int, string> */
    private static array $mapKey = [];

    public static function reset(): void
    {
        self::$refCount = 0;
        self::$mapCount = 0;
        self::$refTargetPtr = [];
        self::$refSlotPtr = [];
        self::$mapTargetPtr = [];
        self::$mapHtPtr = [];
        self::$mapKey = [];
    }

    /** @internal LLVM bridge checks non-zero pointers and capacity */
    public static function appendRefEntry(int $targetPtr, int $slotPtr): void
    {
        $idx = self::$refCount;
        self::$refTargetPtr[$idx] = $targetPtr;
        self::$refSlotPtr[$idx] = $slotPtr;
        self::$refCount = self::$refCount + 1;
    }

    /** @internal LLVM bridge checks non-zero pointers, non-empty key, and capacity */
    public static function appendMapEntry(int $targetPtr, int $htPtr, string $key): void
    {
        $idx = self::$mapCount;
        self::$mapTargetPtr[$idx] = $targetPtr;
        self::$mapHtPtr[$idx] = $htPtr;
        self::$mapKey[$idx] = self::storeMapKey($key);
        self::$mapCount = self::$mapCount + 1;
    }

    public static function formatObjectKey(int $objPtr): string
    {
        return \sprintf('o:%x', $objPtr);
    }

    public static function mapKeyToObjectPtr(string $key): int
    {
        if (!str_starts_with($key, 'o:')) {
            return 0;
        }
        $suffix = substr($key, 2);
        if ('' === $suffix) {
            return 0;
        }

        return (int) \hexdec($suffix);
    }

    public static function refCount(): int
    {
        return self::$refCount;
    }

    public static function refTargetPtr(int $index): int
    {
        if ($index < 0) {
            return 0;
        }
        if ($index >= self::$refCount) {
            return 0;
        }
        if (!isset(self::$refTargetPtr[$index])) {
            return 0;
        }

        return self::$refTargetPtr[$index];
    }

    public static function refSlotPtr(int $index): int
    {
        if ($index < 0) {
            return 0;
        }
        if ($index >= self::$refCount) {
            return 0;
        }
        if (!isset(self::$refSlotPtr[$index])) {
            return 0;
        }

        return self::$refSlotPtr[$index];
    }

    public static function clearRefEntry(int $index): void
    {
        if ($index < 0) {
            return;
        }
        if ($index >= self::$refCount) {
            return;
        }
        self::$refTargetPtr[$index] = 0;
        self::$refSlotPtr[$index] = 0;
    }

    public static function mapCount(): int
    {
        return self::$mapCount;
    }

    public static function mapTargetPtr(int $index): int
    {
        if ($index < 0) {
            return 0;
        }
        if ($index >= self::$mapCount) {
            return 0;
        }
        if (!isset(self::$mapTargetPtr[$index])) {
            return 0;
        }

        return self::$mapTargetPtr[$index];
    }

    public static function mapHtPtr(int $index): int
    {
        if ($index < 0) {
            return 0;
        }
        if ($index >= self::$mapCount) {
            return 0;
        }
        if (!isset(self::$mapHtPtr[$index])) {
            return 0;
        }

        return self::$mapHtPtr[$index];
    }

    public static function mapKey(int $index): string
    {
        if ($index < 0) {
            return '';
        }
        if ($index >= self::$mapCount) {
            return '';
        }
        if (!isset(self::$mapKey[$index])) {
            return '';
        }

        return self::$mapKey[$index];
    }

    public static function clearMapEntry(int $index): void
    {
        if ($index < 0) {
            return;
        }
        if ($index >= self::$mapCount) {
            return;
        }
        self::$mapTargetPtr[$index] = 0;
        self::$mapHtPtr[$index] = 0;
        self::$mapKey[$index] = '';
    }

    private static function storeMapKey(string $key): string
    {
        return $key;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::reset();
    }
}
