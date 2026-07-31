<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * php_stream_parse_fopen_modes — first-character mode validation (php-src plain_wrapper.c).
 *
 * Empty / junk modes must fail before host fopen; never rewrite "" → "rb" (#25941, re-#13401).
 */
final class VmFopenMode
{
    private static ?string $lastOpenFailureDetail = null;

    public static function isValid(string $mode): bool
    {
        if ('' === $mode) {
            return false;
        }
        $base = $mode[0];

        return 'r' === $base || 'w' === $base || 'a' === $base || 'x' === $base || 'c' === $base;
    }

    public static function invalidModeDetail(string $mode): string
    {
        // Concatenation — NestedJIT AOT lacks __compiler_sprintf in StreamIo helper bundle (#25941).
        return '`'.$mode.'\' is not a valid mode for fopen';
    }

    public static function noteInvalidMode(string $mode): void
    {
        self::$lastOpenFailureDetail = self::invalidModeDetail($mode);
    }

    public static function clearLastOpenFailureDetail(): void
    {
        self::$lastOpenFailureDetail = null;
    }

    public static function lastOpenFailureDetail(): ?string
    {
        return self::$lastOpenFailureDetail;
    }

    public static function consumeLastOpenFailureDetail(): ?string
    {
        $detail = self::$lastOpenFailureDetail;
        self::$lastOpenFailureDetail = null;

        return $detail;
    }

    /**
     * Host fopen mode with binary flag when neither b nor t is present (#8950).
     * Caller must validate with {@see isValid()} first.
     */
    public static function phpStreamMode(string $mode): string
    {
        if (!\str_contains($mode, 'b') && !\str_contains($mode, 't')) {
            return $mode.'b';
        }

        return $mode;
    }
}
