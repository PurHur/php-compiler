<?php
declare(strict_types=1);

/**
 * #21552 — Pdo\Mysql / Pdo\Pgsql must not advertise cross-driver PDO_*_Ext methods.
 */
foreach (['Pdo\\Mysql', 'Pdo\\Sqlite', 'Pdo\\Pgsql'] as $c) {
    echo $c, PHP_EOL;
    foreach ([
        'sqliteCreateFunction',
        'sqliteCreateAggregate',
        'pgsqlCopyFromArray',
        'getWarningCount',
        'copyFromArray',
    ] as $m) {
        echo '  ', $m, '=', method_exists($c, $m) ? 'yes' : 'no', PHP_EOL;
    }
}
echo 'PDO:', PHP_EOL;
foreach (['sqliteCreateFunction', 'pgsqlCopyFromArray', 'getWarningCount'] as $m) {
    echo '  ', $m, '=', method_exists('PDO', $m) ? 'yes' : 'no', PHP_EOL;
}
