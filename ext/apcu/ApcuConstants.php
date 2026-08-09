<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

/**
 * PECL apcu iterator / list constants (apc_iterator.h; #27877).
 */
final class ApcuConstants
{
    public const LIST_ACTIVE = 0x1;

    public const LIST_DELETED = 0x2;

    public const ITER_TYPE = 1 << 0;

    public const ITER_KEY = 1 << 1;

    public const ITER_VALUE = 1 << 2;

    public const ITER_NUM_HITS = 1 << 3;

    public const ITER_MTIME = 1 << 4;

    public const ITER_CTIME = 1 << 5;

    public const ITER_DTIME = 1 << 6;

    public const ITER_ATIME = 1 << 7;

    public const ITER_REFCOUNT = 1 << 8;

    public const ITER_MEM_SIZE = 1 << 9;

    public const ITER_TTL = 1 << 10;

    public const ITER_NONE = 0;

    /** @phpstan-var int */
    public const ITER_ALL = 0xffffffff;

    /** @return array<string, int> */
    public static function registeredConstants(): array
    {
        return [
            'APC_LIST_ACTIVE' => self::LIST_ACTIVE,
            'APC_LIST_DELETED' => self::LIST_DELETED,
            'APC_ITER_TYPE' => self::ITER_TYPE,
            'APC_ITER_KEY' => self::ITER_KEY,
            'APC_ITER_VALUE' => self::ITER_VALUE,
            'APC_ITER_NUM_HITS' => self::ITER_NUM_HITS,
            'APC_ITER_MTIME' => self::ITER_MTIME,
            'APC_ITER_CTIME' => self::ITER_CTIME,
            'APC_ITER_DTIME' => self::ITER_DTIME,
            'APC_ITER_ATIME' => self::ITER_ATIME,
            'APC_ITER_REFCOUNT' => self::ITER_REFCOUNT,
            'APC_ITER_MEM_SIZE' => self::ITER_MEM_SIZE,
            'APC_ITER_TTL' => self::ITER_TTL,
            'APC_ITER_NONE' => self::ITER_NONE,
            'APC_ITER_ALL' => self::ITER_ALL,
        ];
    }
}
