--TEST--
stdlib pathinfo() php:// wrapper dirname is scheme + colon (ext/standard/basic_functions.c)
--FILE--
<?php
$mem = pathinfo('php://memory');
echo $mem['dirname'], "\n";
echo $mem['basename'], "\n";
echo $mem['filename'], "\n";
echo pathinfo('php://stdin', PATHINFO_DIRNAME), "\n";
echo pathinfo('php://temp', PATHINFO_DIRNAME), "\n";
echo dirname('php://memory'), "\n";
--EXPECT--
php:
memory
memory
php:
php:
php:
