<?php

declare(strict_types=1);

namespace PHPCompiler\ext\pdo;

/**
 * Pdo\Pgsql class constants (php-src ext/pdo_pgsql/php_pdo_pgsql_int.h + pdo_pgsql.stub.php; #20548).
 *
 * ATTR_* use PDO_ATTR_DRIVER_SPECIFIC (= 1000). TRANSACTION_* match libpq PQTRANS_* values.
 */
final class PdoPgsqlConstants
{
    public const ATTR_DISABLE_PREPARES = 1000;

    public const ATTR_RESULT_MEMORY_SIZE = 1001;

    public const TRANSACTION_IDLE = 0;

    public const TRANSACTION_ACTIVE = 1;

    public const TRANSACTION_INTRANS = 2;

    public const TRANSACTION_INERROR = 3;

    public const TRANSACTION_UNKNOWN = 4;

    /**
     * @var array<string, int>
     */
    public const CLASS_CONSTANTS = [
        'attr_disable_prepares' => self::ATTR_DISABLE_PREPARES,
        'attr_result_memory_size' => self::ATTR_RESULT_MEMORY_SIZE,
        'transaction_idle' => self::TRANSACTION_IDLE,
        'transaction_active' => self::TRANSACTION_ACTIVE,
        'transaction_intrans' => self::TRANSACTION_INTRANS,
        'transaction_inerror' => self::TRANSACTION_INERROR,
        'transaction_unknown' => self::TRANSACTION_UNKNOWN,
    ];

    /** @var array<string, string> */
    public const CLASS_CONSTANT_NAMES = [
        'attr_disable_prepares' => 'ATTR_DISABLE_PREPARES',
        'attr_result_memory_size' => 'ATTR_RESULT_MEMORY_SIZE',
        'transaction_idle' => 'TRANSACTION_IDLE',
        'transaction_active' => 'TRANSACTION_ACTIVE',
        'transaction_intrans' => 'TRANSACTION_INTRANS',
        'transaction_inerror' => 'TRANSACTION_INERROR',
        'transaction_unknown' => 'TRANSACTION_UNKNOWN',
    ];
}
