<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;

/**
 * preg_* for compiled JIT/AOT embed modules (#9542, php-in-PHP).
 *
 * SSOT: {@see VmPregPure} via {@see VmPregNative} + {@see VmPregMatches}
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
        return match (VmPregNative::lastError()) {
            0 => 'No error',
            1 => 'Internal error',
            4 => 'Malformed UTF-8 characters, possibly incorrectly encoded',
            5 => 'The offset did not correspond to the beginning of a valid UTF-8 code point',
            2 => 'Backtrack limit exhausted',
            3 => 'Recursion limit exhausted',
            6 => 'JIT stack limit exhausted',
            default => 'Unknown error',
        };
    }

    /** @return int match count, or -1 on PCRE error */
    public static function matchArgv(string $pattern, string $subject): int
    {
        $trivial = self::matchArgvTrivialUnanchored($pattern, $subject);
        if (null !== $trivial) {
            return $trivial;
        }

        $rc = VmPregNative::pregMatch($pattern, $subject);
        if (false === $rc) {
            return -1;
        }

        return (int) $rc;
    }

    /**
     * AOT fast path for delimiter literals without regex metacharacters (#16075).
     *
     * @return int|null 0/1, or null to defer to VmPregNative
     */
    public static function matchArgvTrivialUnanchored(string $pattern, string $subject): ?int
    {
        $plen = \strlen($pattern);
        if ($plen < 3 || '/' !== $pattern[0]) {
            return null;
        }
        $close = \strrpos($pattern, '/');
        if (false === $close || $close < 2 || $close !== $plen - 1) {
            return null;
        }
        $body = \substr($pattern, 1, $close - 1);
        $bodyLen = \strlen($body);
        for ($i = 0; $i < $bodyLen; ++$i) {
            $c = $body[$i];
            if ('\\' === $c || '[' === $c || '(' === $c || ')' === $c || '|' === $c
                || '*' === $c || '+' === $c || '?' === $c || '{' === $c || '}' === $c
                || '^' === $c || '$' === $c || '.' === $c) {
                return null;
            }
        }
        if (0 === $bodyLen) {
            return 1;
        }
        $subLen = \strlen($subject);
        if ($subLen < $bodyLen) {
            return 0;
        }
        for ($i = 0; $i <= $subLen - $bodyLen; ++$i) {
            if (\strncmp(\substr($subject, $i), $body, $bodyLen) === 0) {
                return 1;
            }
        }

        return 0;
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
        $matches = null;
        $rc = VmPregNative::pregMatch($pattern, $subject, $matches, $flags, $offset);
        if (false === $rc) {
            // Past-end offset fills [] (#25313); compile failure leaves null (#17597).
            if (\is_array($matches)) {
                self::$lastMatchExHt = VmPregMatches::hostMatchesToHashTable($matches, $flags);
            }

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

    /** Thin-AOT capture bridge stubs — real impl in PregJitHelperThinAot (#24115). */
    public static function thinMatchExCapCount(): int
    {
        return 0;
    }

    public static function thinMatchExCap(int $index): string
    {
        return '';
    }

    /** Thin AOT only — full helper never stores split parts here (#27080). */
    public static function thinSplitPartCount(): int
    {
        return 0;
    }

    public static function thinSplitPart(int $index): string
    {
        return '';
    }

    /** Thin AOT only — full helper never stores match_all parts here (#27195). */
    public static function thinMatchAllPartCount(): int
    {
        return 0;
    }

    public static function thinMatchAllPart(int $index): string
    {
        return '';
    }

    /** @return int match count, or -1 on PCRE error */
    public static function matchAllExArgv(string $pattern, string $subject, int $flags, int $offset): int
    {
        self::$lastMatchAllExHt = null;
        $matches = null;
        $rc = VmPregNative::pregMatchAll($pattern, $subject, $matches, $flags, $offset);
        if (false === $rc) {
            // Past-end offset fills [] (#25313); compile failure leaves null (#17597).
            if (\is_array($matches)) {
                self::$lastMatchAllExHt = VmPregMatches::hostMatchAllToHashTable($matches, $flags);
            }

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

    /** Thin-AOT stubs — real find-next impl in PregJitHelperThinAot (#27181). */
    public static function replaceFindNext(string $pattern, string $subject, int $offset): int
    {
        return -1;
    }

    public static function takeLastReplacePos(): int
    {
        return -1;
    }

    public static function takeLastReplaceBodyLen(): int
    {
        return 0;
    }

    public static function splitArgv(string $pattern, string $subject, int $limit, int $flags): ?HashTable
    {
        $parts = VmPregNative::pregSplit($pattern, $subject, $limit, $flags);
        if (false === $parts) {
            return null;
        }

        return VmPreg::splitPartsToHashTable($parts, $flags);
    }

    public static function replaceCallbackArgv(string $pattern, string $subject, int $callbackFnAddr): ?string
    {
        return VmPregNative::pregReplaceCallbackByFnAddr($pattern, $subject, $callbackFnAddr);
    }

    public static function replaceCallbackArrayArgv(HashTable $patterns, string $subject): ?string
    {
        $ctx = \PHPCompiler\Web\Superglobals::getActiveContext();
        if (null === $ctx) {
            throw new \LogicException(
                'PregJitHelper::replaceCallbackArrayArgv() requires an active VM context in this compiler build'
            );
        }

        $subjectVar = new \PHPCompiler\VM\Variable();
        $subjectVar->string($subject);
        $result = VmPregReplaceCallbackArray::invoke($ctx, $patterns, $subjectVar);
        if (false === $result) {
            return null;
        }
        if (\is_string($result)) {
            return $result;
        }

        throw new \LogicException(
            'preg_replace_callback_array() array subject is not supported for JIT/AOT in this compiler build'
        );
    }
}
