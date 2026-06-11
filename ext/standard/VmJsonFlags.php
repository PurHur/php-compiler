<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * json_validate() / json_decode() flag bits (Zend ext/json/php_json.c).
 */
final class VmJsonFlags
{
    /** @see JSON_INVALID_UTF8_IGNORE */
    public const INVALID_UTF8_IGNORE = 1048576;

    /** @see JSON_INVALID_UTF8_SUBSTITUTE */
    public const INVALID_UTF8_SUBSTITUTE = 2097152;

    /** Flags accepted by json_validate() (issue #4085). */
    public const VALIDATE_ALLOWED = self::INVALID_UTF8_IGNORE | self::INVALID_UTF8_SUBSTITUTE;

    public static function assertValidateFlags(int $flags): void
    {
        if (0 !== ($flags & ~self::VALIDATE_ALLOWED)) {
            throw new \ValueError(
                'json_validate(): Argument #3 ($flags) must be a valid flag (allowed flags: JSON_INVALID_UTF8_IGNORE, JSON_INVALID_UTF8_SUBSTITUTE)'
            );
        }
    }

    public static function ignoreInvalidUtf8(int $flags): bool
    {
        return 0 !== ($flags & (self::INVALID_UTF8_IGNORE | self::INVALID_UTF8_SUBSTITUTE));
    }
}
