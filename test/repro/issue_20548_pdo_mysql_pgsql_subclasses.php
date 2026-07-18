<?php
// Repro for #20548 — Pdo\Mysql / Pdo\Pgsql subclasses
declare(strict_types=1);

foreach (['Pdo\\Sqlite', 'Pdo\\Mysql', 'Pdo\\Pgsql'] as $c) {
    echo $c, '=', var_export(class_exists($c), true), PHP_EOL;
}
if (class_exists('Pdo\\Mysql')) {
    echo 'ATTR_USE_BUFFERED_QUERY=', Pdo\Mysql::ATTR_USE_BUFFERED_QUERY, PHP_EOL;
    echo 'getWarningCount=', var_export(method_exists('Pdo\\Mysql', 'getWarningCount'), true), PHP_EOL;
}
if (class_exists('Pdo\\Pgsql')) {
    echo 'ATTR_DISABLE_PREPARES=', Pdo\Pgsql::ATTR_DISABLE_PREPARES, PHP_EOL;
    echo 'escapeIdentifier=', var_export(method_exists('Pdo\\Pgsql', 'escapeIdentifier'), true), PHP_EOL;
}
try {
    PDO::connect('mysql:host=127.0.0.1');
    echo "mysql_ok\n";
} catch (Throwable $e) {
    echo 'mysql_err=', $e->getMessage(), PHP_EOL;
}
try {
    PDO::connect('pgsql:host=127.0.0.1');
    echo "pgsql_ok\n";
} catch (Throwable $e) {
    echo 'pgsql_err=', $e->getMessage(), PHP_EOL;
}
