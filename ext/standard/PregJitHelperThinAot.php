<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * Thin standalone AOT PregJitHelper — same symbols as PregJitHelper (#24115).
 *
 * Fast-path only (no VmPregNative). Captures leave empty matches until HT NestedJIT
 * is safe; match count and replace cover the primary segfault / empty-replace bugs.
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
        return self::matchArgv($pattern, $subject);
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

    public static function takeLastMatchExHashTable(): ?HashTable
    {
        return null;
    }

    public static function matchAllExArgv(string $pattern, string $subject, int $flags, int $offset): int
    {
        return self::matchExArgv($pattern, $subject, $flags, $offset);
    }

    public static function takeLastMatchAllExHashTable(): ?HashTable
    {
        return null;
    }

    public static function replaceArgv(string $pattern, string $replacement, string $subject, int $limit): string
    {
        self::$lastError = 0;
        $kind = PregAotFastPath::patternKind($pattern);
        if (0 === $kind) {
            self::$lastError = 1;

            return '';
        }

        return '' . PregAotFastPath::replaceOrEmpty($pattern, $replacement, $subject, $limit);
    }

    public static function splitArgv(string $pattern, string $subject, int $limit, int $flags): ?HashTable
    {
        self::$lastError = 1;

        return null;
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
