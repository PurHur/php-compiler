<?php

declare(strict_types=1);

namespace PHPCompiler\ext\uuid;

/**
 * UUID_* constants (php/pecl-networking-uuid uuid.stub.php / php_uuid.h; #5910 / #22228).
 */
final class UuidConstants
{
    public const UUID_TYPE_DEFAULT = 0;
    public const UUID_TYPE_TIME = 1;
    public const UUID_TYPE_MD5 = 3;
    public const UUID_TYPE_DCE = 4;
    public const UUID_TYPE_RANDOM = 4;
    public const UUID_TYPE_SHA1 = 5;
    public const UUID_TYPE_NULL = -1;
    public const UUID_TYPE_INVALID = -42;

    public const UUID_VARIANT_NCS = 0;
    public const UUID_VARIANT_DCE = 1;
    public const UUID_VARIANT_MICROSOFT = 2;
    public const UUID_VARIANT_OTHER = 3;

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
            'UUID_TYPE_NULL' => self::UUID_TYPE_NULL,
            'UUID_TYPE_INVALID' => self::UUID_TYPE_INVALID,
            'UUID_VARIANT_NCS' => self::UUID_VARIANT_NCS,
            'UUID_VARIANT_DCE' => self::UUID_VARIANT_DCE,
            'UUID_VARIANT_MICROSOFT' => self::UUID_VARIANT_MICROSOFT,
            'UUID_VARIANT_OTHER' => self::UUID_VARIANT_OTHER,
        ];
    }
}
