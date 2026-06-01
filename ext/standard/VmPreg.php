<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\Variable;

/**
 * VM preg_match() — host PCRE via PHP for reference-compatible captures (issue #93).
 *
 * JIT/AOT use native {@see lib/AOT/runtime/preg_match.c} instead.
 */
final class VmPreg
{
    public const MAX_PATTERN_BYTES = 4096;

    /** Last PREG_* code from VM preg_* (Zend ext/pcre/php_pcre.c). */
    private static int $lastError = 0;

    public static function lastError(): int
    {
        return self::$lastError;
    }

    public static function lastErrorMsg(): string
    {
        return self::errorMsgForCode(self::$lastError);
    }

    public static function errorMsgForCode(int $code): string
    {
        return match ($code) {
            0 => 'No error',
            1 => 'Internal error',
            4 => 'Malformed UTF-8 characters, possibly incorrectly encoded',
            5 => 'The offset did not correspond to the beginning of a valid UTF-8 code point',
            2 => 'Backtrack limit exhausted',
            3 => 'Recursion limit exhausted',
            7 => 'JIT stack limit exhausted',
            default => 'Unknown error',
        };
    }

    private static function syncLastErrorFromHost(): void
    {
        self::$lastError = \preg_last_error();
    }

    public static function validatePregMatchFlags(int $flags): void
    {
        $allowed = self::PREG_MATCH_ALLOWED_FLAGS;
        if (0 !== ($flags & ~$allowed)) {
            throw new \LogicException(
                'preg_match() flags must be a combination of PREG_OFFSET_CAPTURE and PREG_UNMATCHED_AS_NULL in this compiler build'
            );
        }
    }

    public static function validatePregMatchAllFlags(int $flags): void
    {
        $allowed = self::PREG_MATCH_ALLOWED_FLAGS
            | StdlibConstants::PREG_PATTERN_ORDER
            | StdlibConstants::PREG_SET_ORDER;
        if (0 !== ($flags & ~$allowed)) {
            throw new \LogicException(
                'preg_match_all() flags must be a combination of PREG_PATTERN_ORDER, PREG_SET_ORDER, PREG_OFFSET_CAPTURE, and PREG_UNMATCHED_AS_NULL in this compiler build'
            );
        }
    }

    private const PREG_MATCH_ALLOWED_FLAGS = StdlibConstants::PREG_OFFSET_CAPTURE
        | StdlibConstants::PREG_UNMATCHED_AS_NULL;

    public static function pregMatch(
        string $pattern,
        string $subject,
        ?array &$matches = null,
        int $flags = 0,
        int $offset = 0
    ) {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return false;
        }
        self::validatePregMatchFlags($flags);

        $result = \preg_match($pattern, $subject, $matches, $flags, $offset);
        self::syncLastErrorFromHost();

        return $result;
    }

    /**
     * @param-out array $matches
     */
    public static function pregMatchAll(
        string $pattern,
        string $subject,
        ?array &$matches = null,
        int $flags = 0,
        int $offset = 0
    ) {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return false;
        }
        self::validatePregMatchAllFlags($flags);

        $result = \preg_match_all($pattern, $subject, $matches, $flags, $offset);
        self::syncLastErrorFromHost();

        return $result;
    }

    /**
     * @param string|list<string> $subject
     *
     * @return string|list<string>|false|null
     */
    public static function pregFilter(
        string $pattern,
        string $replacement,
        string|array $subject,
        int $limit = -1,
        int $flags = 0
    ) {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return false;
        }

        $result = \preg_filter($pattern, $replacement, $subject, $limit, $flags);
        self::syncLastErrorFromHost();
        if (false === $result) {
            return false;
        }

        return $result;
    }

    public static function pregReplace(
        string $pattern,
        string $replacement,
        string $subject,
        int $limit = -1
    ) {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return false;
        }

        $result = \preg_replace($pattern, $replacement, $subject, $limit);
        self::syncLastErrorFromHost();
        if (null === $result) {
            return false;
        }

        return $result;
    }

    /**
     * @param array $offsetMatches preg_match PREG_OFFSET_CAPTURE
     *
     * @return array
     */
    public static function stripMatchOffsets(array $offsetMatches): array
    {
        $out = [];
        foreach ($offsetMatches as $key => $match) {
            if (\is_array($match) && isset($match[0]) && \is_string($match[0])) {
                $out[$key] = $match[0];
            } elseif (\is_string($match)) {
                $out[$key] = $match;
            } else {
                throw new \LogicException(
                    'preg_replace_callback() internal match shape invalid in this compiler build'
                );
            }
        }

        return $out;
    }

    /**
     * @return list<string>|list<array{0: string, 1: int}>|false
     */
    public static function pregSplit(string $pattern, string $subject, int $limit = -1, int $flags = 0) {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return false;
        }
        $allowed = StdlibConstants::PREG_SPLIT_NO_EMPTY
            | StdlibConstants::PREG_SPLIT_DELIM_CAPTURE
            | StdlibConstants::PREG_SPLIT_OFFSET_CAPTURE;
        if (0 !== ($flags & ~$allowed)) {
            throw new \LogicException(
                'preg_split() flags must be a combination of PREG_SPLIT_* constants in this compiler build'
            );
        }

        $result = \preg_split($pattern, $subject, $limit, $flags);
        self::syncLastErrorFromHost();
        if (false === $result) {
            return false;
        }

        return $result;
    }

    /**
     * @param list<string>|list<array{0: string, 1: int}> $parts
     */
    public static function splitPartsToHashTable(array $parts, int $flags): HashTable
    {
        $offsetCapture = 0 !== ($flags & StdlibConstants::PREG_SPLIT_OFFSET_CAPTURE);
        $ht = new HashTable();
        foreach ($parts as $part) {
            $ht->append(self::splitPartToVariable($part, $offsetCapture));
        }

        return $ht;
    }

    /**
     * @param string|array{0: string, 1: int} $part
     */
    private static function splitPartToVariable(string|array $part, bool $offsetCapture): Variable
    {
        $var = new Variable();
        if ($offsetCapture) {
            if (!\is_array($part) || !isset($part[0], $part[1]) || !\is_string($part[0]) || !\is_int($part[1])) {
                throw new \LogicException(
                    'preg_split() internal offset capture shape invalid in this compiler build'
                );
            }
            $pair = new HashTable();
            $str = new Variable();
            $str->string($part[0]);
            $pair->append($str);
            $off = new Variable();
            $off->int($part[1]);
            $pair->append($off);
            $var->array($pair);

            return $var;
        }
        if (!\is_string($part)) {
            throw new \LogicException(
                'preg_split() internal split part must be a string in this compiler build'
            );
        }
        $var->string($part);

        return $var;
    }
}
