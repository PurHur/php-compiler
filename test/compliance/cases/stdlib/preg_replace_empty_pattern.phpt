--TEST--
stdlib preg_replace() empty // pattern inter-byte insertion (#11024, ext/pcre/php_pcre.c)
--FILE--
<?php
echo preg_replace('//', 'X', 'abc'), "\n";
echo preg_replace('//u', 'Y', 'ab'), "\n";
echo preg_replace('//', 'X', 'abc', 2), "\n";
echo preg_replace('//', 'X', ''), "\n";
?>
--EXPECT--
XaXbXcX
YaYbY
XaXbc
X
