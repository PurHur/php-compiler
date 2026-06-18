<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Lowered into JIT/AOT modules for __phpc_last_error_* ABI (#9454, php-in-PHP).
 *
 * Owns last-error static storage for compiled modules. VM SSOT delegates here via {@see NativeLastError}.
 * php-src: ext/standard/basic_functions.c — error_get_last, error_clear_last
 */
final class ErrorLastJitHelper
{
    private static bool $active = false;

    private static int $type = 0;

    private static string $message = '';

    private static string $file = '';

    private static int $line = 0;

    public static function record(int $type, string $message, string $file, int $line): void
    {
        self::$active = true;
        self::$type = $type;
        self::$message = $message;
        self::$file = $file;
        self::$line = $line;
    }

    public static function clear(): void
    {
        self::$active = false;
        self::$type = 0;
        self::$message = '';
        self::$file = '';
        self::$line = 0;
    }

    /** @return bool LLVM i1 ABI; bridge zext to i32 for __phpc_last_error_is_active */
    public static function isActive(): bool
    {
        return self::$active && '' !== self::$message;
    }

    public static function getType(): int
    {
        return self::$type;
    }

    public static function getMessage(): string
    {
        return self::$message;
    }

    public static function getFile(): string
    {
        return self::$file;
    }

    public static function getLine(): int
    {
        return self::$line;
    }
}
