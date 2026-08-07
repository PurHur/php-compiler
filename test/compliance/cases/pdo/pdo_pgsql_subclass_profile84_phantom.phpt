--TEST--
Pdo\Pgsql withheld on PROFILE=8.4 without pdo_pgsql (#28158)
--ENV--
PHP_COMPILER_PROFILE=8.4
--SKIPIF--
<?php
if (extension_loaded('pdo_pgsql')) die('skip host pdo_pgsql loaded');
?>
--FILE--
<?php
echo 'ext=', extension_loaded('pdo_pgsql') ? '1' : '0', "\n";
echo 'class=', class_exists('Pdo\\Pgsql') ? '1' : '0', "\n";
?>
--EXPECT--
ext=0
class=0
