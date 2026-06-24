<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * stream_supports() feature codes — php-src main/php_streams.h (STREAM_META_*)
 * plus compiler feature probes (STREAM_LOCK, STREAM_FILTER).
 */
final class VmStreamSupports
{
    public const STREAM_META_TOUCH = 1;
    public const STREAM_META_OWNER_NAME = 2;
    public const STREAM_META_OWNER = 3;
    public const STREAM_META_GROUP_NAME = 4;
    public const STREAM_META_GROUP = 5;
    public const STREAM_META_ACCESS = 6;
    public const STREAM_LOCK = 7;
    /** PHP 8.4+ stream_supports() seek probe (php-src main/php_streams.h PHP_STREAM_META_SEEKABLE). */
    public const STREAM_META_SEEKABLE = 8;
    public const STREAM_FILTER = 8;

    /** @return array<string, int> */
    public static function constants(): array
    {
        return [
            'STREAM_META_TOUCH' => self::STREAM_META_TOUCH,
            'STREAM_META_OWNER_NAME' => self::STREAM_META_OWNER_NAME,
            'STREAM_META_OWNER' => self::STREAM_META_OWNER,
            'STREAM_META_GROUP_NAME' => self::STREAM_META_GROUP_NAME,
            'STREAM_META_GROUP' => self::STREAM_META_GROUP,
            'STREAM_META_ACCESS' => self::STREAM_META_ACCESS,
            'STREAM_LOCK' => self::STREAM_LOCK,
            'STREAM_META_SEEKABLE' => self::STREAM_META_SEEKABLE,
            'STREAM_FILTER' => self::STREAM_FILTER,
        ];
    }
}
