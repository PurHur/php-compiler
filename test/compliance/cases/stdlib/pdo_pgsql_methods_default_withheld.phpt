--TEST--
pdo_pgsql: default profile withholds PDO::pgsql* without host/ENABLE (#27850)
--SKIPIF--
<?php
if (!class_exists('PDO')) die('skip no PDO');
if (extension_loaded('pdo_pgsql')) die('skip host pdo_pgsql loaded');
?>
--FILE--
<?php
declare(strict_types=1);

echo 'ext=', (int) extension_loaded('pdo_pgsql'), "\n";
echo 'pgsqlGetPid=', (int) method_exists('PDO', 'pgsqlGetPid'), "\n";
echo 'pgsqlCopyFromArray=', (int) method_exists('PDO', 'pgsqlCopyFromArray'), "\n";
?>
--EXPECT--
ext=0
pgsqlGetPid=0
pgsqlCopyFromArray=0
