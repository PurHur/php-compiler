<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * preg_* for compiled JIT/AOT embed modules (#9542, php-in-PHP).
 *
 * SSOT: {@see VmPregNative} + {@see VmPregMatches}
 * php-src: ext/pcre/php_pcre.c
 */
final class PregJitHelper
{
    private static ?HashTable $lastMatchExHt = null;

    private static ?HashTable $lastMatchAllExHt = null;

    public static function lastError(): int
    {
        return VmPregNative::lastError();
    }

    public static function lastErrorMsg(): string
    {
        return VmPreg::errorMsgForCode(VmPregNative::lastError());
    }

    /** @return int match count, or -1 on PCRE error */
    public static function matchArgv(string $pattern, string $subject): int
    {
        $rc = VmPregNative::pregMatch($pattern, $subject);
        if (false === $rc) {
            return -1;
        }

        return (int) $rc;
    }

    /** @return int match count, or -1 on PCRE error */
    public static function matchAllArgv(string $pattern, string $subject): int
    {
        $rc = VmPregNative::pregMatchAll($pattern, $subject);
        if (false === $rc) {
            return -1;
        }

        return (int) $rc;
    }

    /** @return int match count, or -1 on PCRE error */
    public static function matchExArgv(string $pattern, string $subject, int $flags, int $offset): int
    {
        self::$lastMatchExHt = null;
        $matches = [];
        $rc = VmPregNative::pregMatch($pattern, $subject, $matches, $flags, $offset);
        if (false === $rc) {
            return -1;
        }
        self::$lastMatchExHt = VmPregMatches::hostMatchesToHashTable($matches, $flags);

        return (int) $rc;
    }

    public static function takeLastMatchExHashTable(): ?HashTable
    {
        $ht = self::$lastMatchExHt;
        self::$lastMatchExHt = null;

        return $ht;
    }

    /** @return int match count, or -1 on PCRE error */
    public static function matchAllExArgv(string $pattern, string $subject, int $flags, int $offset): int
    {
        self::$lastMatchAllExHt = null;
        $matches = [];
        $rc = VmPregNative::pregMatchAll($pattern, $subject, $matches, $flags, $offset);
        if (false === $rc) {
            return -1;
        }
        self::$lastMatchAllExHt = VmPregMatches::hostMatchAllToHashTable($matches, $flags);

        return (int) $rc;
    }

    public static function takeLastMatchAllExHashTable(): ?HashTable
    {
        $ht = self::$lastMatchAllExHt;
        self::$lastMatchAllExHt = null;

        return $ht;
    }

    public static function replaceArgv(string $pattern, string $replacement, string $subject, int $limit): ?string
    {
        $result = VmPregNative::pregReplace($pattern, $replacement, $subject, $limit);
        if (false === $result || !\is_string($result)) {
            return null;
        }

        return $result;
    }

    public static function splitArgv(string $pattern, string $subject, int $limit, int $flags): ?HashTable
    {
        $parts = VmPregNative::pregSplit($pattern, $subject, $limit, $flags);
        if (false === $parts) {
            return null;
        }

        return VmPreg::splitPartsToHashTable($parts, $flags);
    }
}
