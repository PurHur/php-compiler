<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * WeakMap / WeakReference registry for compiled JIT/AOT modules (#9191, php-in-PHP).
 *
 * Slot-table ABI mirrors legacy WeakRefRegistryRuntime LLVM globals. VM SSOT remains
 * {@see \PHPCompiler\VM\WeakRefRegistry} for interpreted code.
 * php-src: Zend/zend_weakrefs.c
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

    public static function registerRef(int $targetPtr, int $slotPtr): void
    {
        if (0 === $targetPtr || 0 === $slotPtr) {
            return;
        }
        if (self::$refCount >= self::MAX_REFS) {
            return;
        }
        $idx = self::$refCount;
        self::$refTargetPtr[$idx] = $targetPtr;
        self::$refSlotPtr[$idx] = $slotPtr;
        ++self::$refCount;
    }

    public static function registerMap(int $targetPtr, int $htPtr, string $key): void
    {
        if (0 === $targetPtr || 0 === $htPtr || '' === $key) {
            return;
        }
        if (self::$mapCount >= self::MAX_MAPS) {
            return;
        }
        $stored = self::storeMapKey($key);
        $idx = self::$mapCount;
        self::$mapTargetPtr[$idx] = $targetPtr;
        self::$mapHtPtr[$idx] = $htPtr;
        self::$mapKey[$idx] = $stored;
        ++self::$mapCount;
    }

    public static function unregisterMap(int $targetPtr, int $htPtr, string $key): void
    {
        if (0 === $targetPtr || 0 === $htPtr || '' === $key) {
            return;
        }
        $stored = self::storeMapKey($key);
        for ($i = 0; $i < self::$mapCount; ++$i) {
            if (self::$mapTargetPtr[$i] === $targetPtr) {
                if (self::$mapHtPtr[$i] === $htPtr) {
                    if (self::$mapKey[$i] === $stored) {
                        self::clearMapEntry($i);

                        return;
                    }
                }
            }
        }
    }

    public static function formatObjectKey(int $objPtr): string
    {
        if (0 === $objPtr) {
            return '';
        }

        return \sprintf('o:%x', $objPtr);
    }

    public static function mapKeyToObjectPtr(string $key): int
    {
        $len = \strlen($key);
        if ($len < 3) {
            return 0;
        }
        if ('o' !== $key[0]) {
            return 0;
        }
        if (':' !== $key[1]) {
            return 0;
        }
        $suffix = \substr($key, 2);
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
        if ($index < 0 || $index >= self::$refCount) {
            return 0;
        }
        if (!isset(self::$refTargetPtr[$index])) {
            return 0;
        }

        return self::$refTargetPtr[$index];
    }

    public static function refSlotPtr(int $index): int
    {
        if ($index < 0 || $index >= self::$refCount) {
            return 0;
        }
        if (!isset(self::$refSlotPtr[$index])) {
            return 0;
        }

        return self::$refSlotPtr[$index];
    }

    public static function clearRefEntry(int $index): void
    {
        if ($index < 0 || $index >= self::$refCount) {
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
        if ($index < 0 || $index >= self::$mapCount) {
            return 0;
        }
        if (!isset(self::$mapTargetPtr[$index])) {
            return 0;
        }

        return self::$mapTargetPtr[$index];
    }

    public static function mapHtPtr(int $index): int
    {
        if ($index < 0 || $index >= self::$mapCount) {
            return 0;
        }
        if (!isset(self::$mapHtPtr[$index])) {
            return 0;
        }

        return self::$mapHtPtr[$index];
    }

    public static function mapKey(int $index): string
    {
        if ($index < 0 || $index >= self::$mapCount) {
            return '';
        }
        if (!isset(self::$mapKey[$index])) {
            return '';
        }

        return self::$mapKey[$index];
    }

    public static function clearMapEntry(int $index): void
    {
        if ($index < 0 || $index >= self::$mapCount) {
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
