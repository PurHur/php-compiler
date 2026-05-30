<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

/**
 * Tracks whether script output has started (headers_sent parity, issue #3120).
 *
 * Mirrors php-src sapi_headers_sent() for CLI: true after the first body byte
 * reaches the SAPI output layer, false while output buffering captures echo.
 */
final class SapiOutput
{
    private static bool $started = false;

    private static ?string $file = null;

    private static int $line = 0;

    public static function reset(): void
    {
        self::$started = false;
        self::$file = null;
        self::$line = 0;
    }

    public static function markStarted(?string $file = null, int $line = 0): void
    {
        if (self::$started) {
            return;
        }
        self::$started = true;
        self::$file = $file;
        self::$line = $line;
    }

    public static function headersSent(): bool
    {
        return self::$started;
    }

    public static function sentFile(): ?string
    {
        return self::$file;
    }

    public static function sentLine(): int
    {
        return self::$line;
    }
}
