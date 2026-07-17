<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

/**
 * PDO class constants (php-src ext/pdo/pdo.stub.php; #3367).
 */
final class PdoConstants
{
    public const ATTR_ERRMODE = 3;

    public const ATTR_DEFAULT_FETCH_MODE = 19;

    public const ERRMODE_SILENT = 0;

    public const ERRMODE_WARNING = 1;

    public const ERRMODE_EXCEPTION = 2;

    public const FETCH_ASSOC = 2;

    public const FETCH_NUM = 3;

    public const FETCH_BOTH = 4;

    public const PARAM_NULL = 0;

    public const PARAM_INT = 1;

    public const PARAM_STR = 2;

    public const PARAM_BOOL = 5;

    /** @var array<string, int> */
    public const CLASS_CONSTANTS = [
        'ATTR_ERRMODE' => self::ATTR_ERRMODE,
        'ATTR_DEFAULT_FETCH_MODE' => self::ATTR_DEFAULT_FETCH_MODE,
        'ERRMODE_SILENT' => self::ERRMODE_SILENT,
        'ERRMODE_WARNING' => self::ERRMODE_WARNING,
        'ERRMODE_EXCEPTION' => self::ERRMODE_EXCEPTION,
        'FETCH_ASSOC' => self::FETCH_ASSOC,
        'FETCH_NUM' => self::FETCH_NUM,
        'FETCH_BOTH' => self::FETCH_BOTH,
        'PARAM_NULL' => self::PARAM_NULL,
        'PARAM_INT' => self::PARAM_INT,
        'PARAM_STR' => self::PARAM_STR,
        'PARAM_BOOL' => self::PARAM_BOOL,
    ];
}
