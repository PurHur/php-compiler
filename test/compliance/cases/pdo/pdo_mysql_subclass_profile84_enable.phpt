--TEST--
Pdo\Mysql present on PROFILE=8.4 with ENABLE (#27332)
--ENV--
PHP_COMPILER_ENABLE_PDO_MYSQL=1
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PDO')) die('skip no PDO');
?>
--FILE--
<?php
echo 'ext=', extension_loaded('pdo_mysql') ? '1' : '0', "\n";
echo 'class=', class_exists('Pdo\\Mysql') ? '1' : '0', "\n";
echo 'warn=', method_exists('Pdo\\Mysql', 'getWarningCount') ? '1' : '0', "\n";
?>
--EXPECT--
ext=1
class=1
warn=1
