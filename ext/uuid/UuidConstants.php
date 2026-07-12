<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

/**
 * UUID_TYPE_* constants (php/pecl-networking-uuid REFLECTION; issue #5910).
 */
final class UuidConstants
{
    public const UUID_TYPE_DEFAULT = 0;
    public const UUID_TYPE_TIME = 1;
    public const UUID_TYPE_MD5 = 3;
    public const UUID_TYPE_DCE = 4;
    public const UUID_TYPE_RANDOM = 4;
    public const UUID_TYPE_SHA1 = 5;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'UUID_TYPE_DEFAULT' => self::UUID_TYPE_DEFAULT,
            'UUID_TYPE_TIME' => self::UUID_TYPE_TIME,
            'UUID_TYPE_MD5' => self::UUID_TYPE_MD5,
            'UUID_TYPE_DCE' => self::UUID_TYPE_DCE,
            'UUID_TYPE_RANDOM' => self::UUID_TYPE_RANDOM,
            'UUID_TYPE_SHA1' => self::UUID_TYPE_SHA1,
        ];
    }
}
