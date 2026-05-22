<?php

declare(strict_types=1);

namespace PHPCompiler;

/**
 * Sentinel literal values for __DIR__ / __FILE__ deferred to runtime (issue #85, #707).
 */
final class MagicLiteral
{
    public const DIR = "\0PHPC_MAGIC_DIR\0";

    public const FILE = "\0PHPC_MAGIC_FILE\0";

    public static function isDir(mixed $value): bool
    {
        return self::DIR === $value;
    }

    public static function isFile(mixed $value): bool
    {
        return self::FILE === $value;
    }

    public static function isMagicString(mixed $value): bool
    {
        return is_string($value) && (self::isDir($value) || self::isFile($value));
    }
}
