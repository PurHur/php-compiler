--TEST--
Pdo\Mysql withheld on PROFILE=8.4 without pdo_mysql (#27332)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (extension_loaded('pdo_mysql')) die('skip host pdo_mysql loaded');
?>
--FILE--
<?php
echo 'ext=', extension_loaded('pdo_mysql') ? '1' : '0', "\n";
echo 'class=', class_exists('Pdo\\Mysql') ? '1' : '0', "\n";
?>
--EXPECT--
ext=0
class=0
