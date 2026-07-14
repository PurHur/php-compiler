<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sqlite3;

/**
 * SQLite3 class constants (php-src ext/sqlite3/sqlite3.stub.php; issue #3434).
 */
final class Sqlite3Constants
{
    public const OPEN_READONLY = 1;

    public const OPEN_READWRITE = 2;

    public const OPEN_CREATE = 4;

    public const ASSOC = 1;

    public const NUM = 2;

    public const BOTH = 3;

    /** @var array<string, int> */
    public const CLASS_CONSTANTS = [
        'OPEN_READONLY' => self::OPEN_READONLY,
        'OPEN_READWRITE' => self::OPEN_READWRITE,
        'OPEN_CREATE' => self::OPEN_CREATE,
        'ASSOC' => self::ASSOC,
        'NUM' => self::NUM,
        'BOTH' => self::BOTH,
    ];

    /** @var array<string, string> */
    public const CLASS_CONSTANT_NAMES = [
        'OPEN_READONLY' => 'OPEN_READONLY',
        'OPEN_READWRITE' => 'OPEN_READWRITE',
        'OPEN_CREATE' => 'OPEN_CREATE',
        'ASSOC' => 'ASSOC',
        'NUM' => 'NUM',
        'BOTH' => 'BOTH',
    ];
}
