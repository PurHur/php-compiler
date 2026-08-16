<?php
// Repro for #27850 — PDO::pgsql* withheld by default; PROFILE≥8.4 on Pdo\Pgsql only
declare(strict_types=1);

echo 'PDO_pgsqlGetPid=', method_exists(PDO::class, 'pgsqlGetPid') ? '1' : '0', "\n";
echo 'class_Pgsql=', class_exists('Pdo\\Pgsql') ? '1' : '0', "\n";
if (class_exists('Pdo\\Pgsql')) {
    echo 'Pgsql_pgsqlGetPid=', method_exists('Pdo\\Pgsql', 'pgsqlGetPid') ? '1' : '0', "\n";
    echo 'Pgsql_getPid=', method_exists('Pdo\\Pgsql', 'getPid') ? '1' : '0', "\n";
}
if (class_exists('Pdo\\Mysql')) {
    echo 'Mysql_pgsqlGetPid=', method_exists('Pdo\\Mysql', 'pgsqlGetPid') ? '1' : '0', "\n";
}
