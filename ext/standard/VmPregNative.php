<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * VM preg_* — pure PHP via {@see VmPregPure} (#8935, #4874, #1492).
 *
 * php-src: ext/pcre/php_pcre.c
 * JIT/AOT: {@see PregJitHelper} + {@see PregMatchRuntime}
 */
final class VmPregNative
{
    public static function lastError(): int
    {
        return VmPregPure::lastError();
    }

    public static function setLastError(int $code): void
    {
        VmPregPure::setLastError($code);
    }

    public static function pregMatch(
        string $pattern,
        string $subject,
        ?array &$matches = null,
        int $flags = 0,
        int $offset = 0
    ): int|false {
        return VmPregPure::pregMatch($pattern, $subject, $matches, $flags, $offset);
    }

    public static function pregMatchAll(
        string $pattern,
        string $subject,
        ?array &$matches = null,
        int $flags = 0,
        int $offset = 0
    ): int|false {
        return VmPregPure::pregMatchAll($pattern, $subject, $matches, $flags, $offset);
    }

    /**
     * @param string|list<string> $subject
     *
     * @return string|list<string>|null|false
     */
    public static function pregReplace(
        string $pattern,
        string $replacement,
        string|array $subject,
        int $limit = -1,
        ?int &$count = null
    ): string|array|null|false {
        return VmPregPure::pregReplace($pattern, $replacement, $subject, $limit, $count);
    }

    /**
     * @return list<string>|list<array{0: string, 1: int}>|false
     */
    public static function pregSplit(string $pattern, string $subject, int $limit = -1, int $flags = 0): array|false
    {
        return VmPregPure::pregSplit($pattern, $subject, $limit, $flags);
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
        ?int &$count = null
    ): string|array|null|false {
        return VmPregPure::pregFilter($pattern, $replacement, $subject, $limit, $count);
    }

    public static function patternWarningMessage(string $pattern): ?string
    {
        return VmPregPure::patternWarningMessage($pattern);
    }

    /**
     * preg_replace_callback() match loop for JIT/AOT PHP bridge (#13736).
     *
     * @param callable(array<int|string, string>): string $invokeMatchCallback
     */
    public static function pregReplaceCallbackJit(
        string $pattern,
        string $subject,
        callable $invokeMatchCallback,
        int $limit = -1
    ): ?string {
        if (\strlen($pattern) > VmPreg::MAX_PATTERN_BYTES) {
            self::setLastError(1);

            return null;
        }

        $result = '';
        $offset = 0;
        $len = \strlen($subject);
        $replacements = 0;

        while ($offset < $len) {
            if ($limit >= 0 && $replacements >= $limit) {
                $result .= \substr($subject, $offset);

                break;
            }

            $matches = [];
            $matchCount = self::pregMatch(
                $pattern,
                $subject,
                $matches,
                StdlibConstants::PREG_OFFSET_CAPTURE,
                $offset
            );
            if (false === $matchCount) {
                return null;
            }
            if (0 === $matchCount) {
                $result .= \substr($subject, $offset);

                break;
            }

            $full = $matches[0];
            $matchStart = $full[1];
            $matchText = $full[0];
            $matchLen = \strlen($matchText);
            $result .= \substr($subject, $offset, $matchStart - $offset);

            $stripped = VmPreg::stripMatchOffsets($matches);
            $replacement = $invokeMatchCallback($stripped);
            $result .= (string) $replacement;

            ++$replacements;

            $next = $matchStart + $matchLen;
            if ($next <= $offset) {
                self::setLastError(1);

                return null;
            }
            $offset = $next;
        }

        self::setLastError(0);

        return $result;
    }
}
