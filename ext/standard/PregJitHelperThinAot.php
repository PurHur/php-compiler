<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Thin standalone AOT PregJitHelper — same symbols as PregJitHelper (#24115).
 *
 * Fast-path only (no VmPregNative). Captures are string slots read by
 * {@see PregMatchRuntime} LLVM bridge (NestedJIT cannot return `__hashtable__*`).
 */
final class PregJitHelper
{
    private static int $lastError = 0;

    private static ?HashTable $lastMatchExHt = null;

    private static ?HashTable $lastMatchAllExHt = null;

    public static function lastError(): int
    {
        return self::$lastError;
    }

    public static function lastErrorMsg(): string
    {
        if (0 === self::$lastError) {
            return 'No error';
        }

        return 'Internal error';
    }

    public static function matchArgv(string $pattern, string $subject): int
    {
        self::$lastError = 0;
        $code = PregAotFastPath::matchCount($pattern, $subject, 0);
        if ($code < 0) {
            self::$lastError = 1;

            return -1;
        }
        if (0 === $code) {
            return 0;
        }

        return 1;
    }

    public static function matchAllArgv(string $pattern, string $subject): int
    {
        self::$lastError = 0;
        $n = PregAotFastPath::matchAllStore($pattern, $subject, 0, 0);
        if ($n < 0) {
            self::$lastError = 1;

            return -1;
        }

        return $n;
    }

    public static function matchExArgv(string $pattern, string $subject, int $flags, int $offset): int
    {
        self::$lastError = 0;
        self::$lastMatchExHt = null;
        $code = PregAotFastPath::matchCount($pattern, $subject, $offset);
        if ($code < 0) {
            self::$lastError = 1;

            return -1;
        }
        if (0 === $code) {
            return 0;
        }

        return 1;
    }

    /** Always null — HT filled from lastCap* in PregMatchRuntime (#24115). */
    public static function takeLastMatchExHashTable(): ?HashTable
    {
        return null;
    }

    public static function thinMatchExCapCount(): int
    {
        $n = PregAotFastPath::lastCapCount();
        if (0 === $n) {
            return 0;
        }

        return $n;
    }

    public static function thinMatchExCap(int $index): string
    {
        return '' . PregAotFastPath::lastCap($index);
    }

    public static function matchAllExArgv(string $pattern, string $subject, int $flags, int $offset): int
    {
        self::$lastError = 0;
        self::$lastMatchAllExHt = null;
        $n = PregAotFastPath::matchAllStore($pattern, $subject, $flags, $offset);
        if ($n < 0) {
            self::$lastError = 1;

            return -1;
        }

        return $n;
    }

    /** Always null — HT filled from matchAllPart* in PregMatchRuntime (#27195). */
    public static function takeLastMatchAllExHashTable(): ?HashTable
    {
        return null;
    }

    public static function thinMatchAllPartCount(): int
    {
        return PregAotFastPath::matchAllPartCount();
    }

    public static function thinMatchAllPart(int $index): string
    {
        return '' . PregAotFastPath::matchAllPart($index);
    }

    /**
     * Int status for thin AOT find — LLVM builds the string from durable subject/replacement (#27181).
     *
     * @return int 1 matched, 0 no match, -1 unsupported
     */
    public static function replaceFindNext(string $pattern, string $subject, int $offset): int
    {
        self::$lastError = 0;
        $rc = PregAotFastPath::replaceFindNext($pattern, $subject, $offset);
        if ($rc < 0) {
            self::$lastError = 1;
        }

        return $rc;
    }

    public static function takeLastReplacePos(): int
    {
        return PregAotFastPath::takeLastReplacePos();
    }

    public static function takeLastReplaceBodyLen(): int
    {
        return PregAotFastPath::takeLastReplaceBodyLen();
    }

    /**
     * Unused under thin AOT (LLVM find+concat bridge) — kept for COMPILED_HELPERS parity.
     *
     * @return int always -1
     */
    public static function replaceArgv(string $pattern, string $replacement, string $subject, int $limit): int
    {
        return -1;
    }

    public static function splitArgv(string $pattern, string $subject, int $limit, int $flags): ?HashTable
    {
        // Thin AOT: NestedJIT cannot return/build `__hashtable__*` (#27080). Store parts in
        // PregAotFastPath slots; LLVM may fill from thinSplitPart* — prefer literal fold
        // ({@see JitPregSplitCompileTime}) or implementThinSplitBridge (#27208).
        self::$lastError = 0;
        $n = PregAotFastPath::splitStore($pattern, $subject, $limit, $flags);
        if ($n < 0) {
            self::$lastError = 1;

            return null;
        }

        return null;
    }

    public static function thinSplitPartCount(): int
    {
        return PregAotFastPath::splitPartCount();
    }

    public static function thinSplitPart(int $index): string
    {
        return '' . PregAotFastPath::splitPart($index);
    }

    public static function replaceCallbackArgv(string $pattern, string $subject, int $callbackFnAddr): ?string
    {
        self::$lastError = 1;

        return null;
    }

    public static function replaceCallbackArrayArgv(HashTable $patterns, string $subject): ?string
    {
        self::$lastError = 1;

        return null;
    }
}
