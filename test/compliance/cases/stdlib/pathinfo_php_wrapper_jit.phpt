--TEST--
stdlib pathinfo() php:// wrapper dirname JIT (ext/standard/basic_functions.c)
--JIT--
--FILE--
<?php
$mem = pathinfo('php://memory');
echo $mem['dirname'], "\n";
echo pathinfo('php://stdin', PATHINFO_DIRNAME), "\n";
--EXPECT--
php:
php:
