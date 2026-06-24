<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_bucket_* registry for compiled JIT/AOT modules (#9380, php-in-PHP).
 *
 * Slot-table ABI mirrors legacy StreamBucketRuntime LLVM globals. VM SSOT for
 * user-facing builtins remains {@see VmStreamBucket}.
 * php-src: ext/standard/streams.c — stream_bucket_new, brigade helpers
 */
final class StreamBucketJitHelper
{
    public const BUCKET_HANDLE_BASE = 0x30000000;

    public const BRIGADE_HANDLE_BASE = 0x40000000;

    private const MAX_BUCKETS = 256;

    private const MAX_BRIGADES = 64;

    private const MAX_QUEUE = 32;

    /** @var array<int, bool> */
    private static array $bucketActive = [];

    /** @var array<int, string> */
    private static array $bucketData = [];

    /** @var array<int, bool> */
    private static array $brigadeActive = [];

    /** @var array<int, int> */
    private static array $brigadeCount = [];

    /** @var array<int, int> */
    private static array $brigadeQueue = [];

    public static function registerBucket(string $data): int
    {
        for ($slot = 0; $slot < self::MAX_BUCKETS; ++$slot) {
            if (!isset(self::$bucketActive[$slot]) || !self::$bucketActive[$slot]) {
                self::$bucketActive[$slot] = true;
                self::$bucketData[$slot] = $data;

                return self::BUCKET_HANDLE_BASE + $slot;
            }
        }

        return -1;
    }

    public static function bucketData(int $handle): string
    {
        $slot = $handle - self::BUCKET_HANDLE_BASE;
        if ($slot < 0 || $slot >= self::MAX_BUCKETS) {
            return '';
        }
        if (!isset(self::$bucketActive[$slot]) || !self::$bucketActive[$slot]) {
            return '';
        }

        return self::$bucketData[$slot];
    }

    public static function isBucketResource(int $handle): int
    {
        $slot = $handle - self::BUCKET_HANDLE_BASE;
        if ($slot < 0 || $slot >= self::MAX_BUCKETS) {
            return 0;
        }

        return (isset(self::$bucketActive[$slot]) && self::$bucketActive[$slot]) ? 1 : 0;
    }

    public static function isBrigadeResource(int $handle): int
    {
        $slot = $handle - self::BRIGADE_HANDLE_BASE;
        if ($slot < 0 || $slot >= self::MAX_BRIGADES) {
            return 0;
        }

        return (isset(self::$brigadeActive[$slot]) && self::$brigadeActive[$slot]) ? 1 : 0;
    }

    public static function brigadeAlloc(): int
    {
        for ($slot = 0; $slot < self::MAX_BRIGADES; ++$slot) {
            if (!isset(self::$brigadeActive[$slot]) || !self::$brigadeActive[$slot]) {
                self::$brigadeActive[$slot] = true;
                self::$brigadeCount[$slot] = 0;

                return self::BRIGADE_HANDLE_BASE + $slot;
            }
        }

        return -1;
    }

    public static function brigadePush(int $brigadeHandle, int $bucketHandle): int
    {
        $slot = $brigadeHandle - self::BRIGADE_HANDLE_BASE;
        if ($slot < 0 || $slot >= self::MAX_BRIGADES) {
            return 0;
        }
        if (!isset(self::$brigadeActive[$slot]) || !self::$brigadeActive[$slot]) {
            return 0;
        }
        $count = self::$brigadeCount[$slot] ?? 0;
        if ($count >= self::MAX_QUEUE) {
            return 0;
        }
        self::$brigadeQueue[$slot * self::MAX_QUEUE + $count] = $bucketHandle;
        self::$brigadeCount[$slot] = $count + 1;

        return 1;
    }

    public static function brigadePop(int $brigadeHandle): int
    {
        $slot = $brigadeHandle - self::BRIGADE_HANDLE_BASE;
        if ($slot < 0 || $slot >= self::MAX_BRIGADES) {
            return -1;
        }
        if (!isset(self::$brigadeActive[$slot]) || !self::$brigadeActive[$slot]) {
            return -1;
        }
        $count = self::$brigadeCount[$slot] ?? 0;
        if (0 === $count) {
            return -1;
        }
        $headIndex = $slot * self::MAX_QUEUE;
        $handle = self::$brigadeQueue[$headIndex];
        $newCount = $count - 1;
        self::$brigadeCount[$slot] = $newCount;
        for ($i = 0; $i < $newCount; ++$i) {
            self::$brigadeQueue[$headIndex + $i] = self::$brigadeQueue[$headIndex + $i + 1];
        }

        return $handle;
    }

    /** @internal test reset */
    public static function resetForTest(): void
    {
        self::$bucketActive = [];
        self::$bucketData = [];
        self::$brigadeActive = [];
        self::$brigadeCount = [];
        self::$brigadeQueue = [];
    }
}
