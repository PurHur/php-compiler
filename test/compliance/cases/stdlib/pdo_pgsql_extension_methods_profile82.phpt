--TEST--
pdo_pgsql: PROFILE=8.2 advertises PDO::pgsql* when enabled (#20566 / #27850)
--ENV--
PHP_COMPILER_ENABLE_PDO_PGSQL=1
PHP_COMPILER_PROFILE=8.2
--SKIPIF--
<?php
if (!class_exists('PDO')) die('skip no PDO');
?>
--FILE--
<?php
declare(strict_types=1);

echo 'ext=', (int) extension_loaded('pdo_pgsql'), "\n";
echo 'class=', (int) class_exists('Pdo\\Pgsql'), "\n";
echo 'pgsqlCopyFromArray=', (int) method_exists('PDO', 'pgsqlCopyFromArray'), "\n";
echo 'pgsqlCopyToArray=', (int) method_exists('PDO', 'pgsqlCopyToArray'), "\n";
echo 'pgsqlGetNotify=', (int) method_exists('PDO', 'pgsqlGetNotify'), "\n";
echo 'pgsqlGetPid=', (int) method_exists('PDO', 'pgsqlGetPid'), "\n";
echo 'has_pgsql_driver=', (int) in_array('pgsql', PDO::getAvailableDrivers(), true), "\n";
?>
--EXPECT--
ext=1
class=0
pgsqlCopyFromArray=1
pgsqlCopyToArray=1
pgsqlGetNotify=1
pgsqlGetPid=1
has_pgsql_driver=1
