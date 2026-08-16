--TEST--
pdo_pgsql: PROFILE≥8.4 keeps pgsql* off PDO, on Pdo\Pgsql (#27850 / #20566)
--ENV--
PHP_COMPILER_ENABLE_PDO_PGSQL=1
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PDO')) die('skip no PDO');
?>
--FILE--
<?php
declare(strict_types=1);

echo 'ext=', (int) extension_loaded('pdo_pgsql'), "\n";
echo 'PDO_pgsqlGetPid=', (int) method_exists('PDO', 'pgsqlGetPid'), "\n";
echo 'PDO_pgsqlCopyFromArray=', (int) method_exists('PDO', 'pgsqlCopyFromArray'), "\n";
echo 'Pgsql_pgsqlGetPid=', (int) method_exists('Pdo\\Pgsql', 'pgsqlGetPid'), "\n";
echo 'Pgsql_pgsqlCopyFromArray=', (int) method_exists('Pdo\\Pgsql', 'pgsqlCopyFromArray'), "\n";
echo 'Pgsql_getPid=', (int) method_exists('Pdo\\Pgsql', 'getPid'), "\n";
echo 'has_pgsql_driver=', (int) in_array('pgsql', PDO::getAvailableDrivers(), true), "\n";
?>
--EXPECT--
ext=1
PDO_pgsqlGetPid=0
PDO_pgsqlCopyFromArray=0
Pgsql_pgsqlGetPid=1
Pgsql_pgsqlCopyFromArray=1
Pgsql_getPid=1
has_pgsql_driver=1
