<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

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

    /**
     * @param-out array $matches
     */
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
        if (0 !== $flags) {
            throw new \LogicException('preg_match() flags are not supported in this compiler build');
        }
        if (0 !== $offset) {
            throw new \LogicException('preg_match() offset is not supported in this compiler build');
        }

        $result = \preg_match($pattern, $subject, $matches);
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
        if (0 !== $flags) {
            throw new \LogicException('preg_match_all() flags are not supported in this compiler build');
        }
        if (0 !== $offset) {
            throw new \LogicException('preg_match_all() offset is not supported in this compiler build');
        }

        $result = \preg_match_all($pattern, $subject, $matches);
        self::syncLastErrorFromHost();

        return $result;
    }

    public static function pregReplace(string $pattern, string $replacement, string $subject) {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return false;
        }

        $result = \preg_replace($pattern, $replacement, $subject);
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
     * @return list<string>|false
     */
    public static function pregSplit(string $pattern, string $subject) {
        if (strlen($pattern) > self::MAX_PATTERN_BYTES) {
            return false;
        }

        $result = \preg_split($pattern, $subject);
        self::syncLastErrorFromHost();
        if (false === $result) {
            return false;
        }

        return $result;
    }
}
