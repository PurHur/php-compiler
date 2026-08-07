--TEST--
Pdo\Pgsql present on PROFILE=8.4 with ENABLE (#28158)
--ENV--
PHP_COMPILER_ENABLE_PDO_PGSQL=1
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (!class_exists('PDO')) die('skip no PDO');
?>
--FILE--
<?php
echo 'ext=', extension_loaded('pdo_pgsql') ? '1' : '0', "\n";
echo 'class=', class_exists('Pdo\\Pgsql') ? '1' : '0', "\n";
echo 'esc=', method_exists('Pdo\\Pgsql', 'escapeIdentifier') ? '1' : '0', "\n";
?>
--EXPECT--
ext=1
class=1
esc=1
