--TEST--
JIT: preg_replace() empty // pattern (#11024, ext/pcre/php_pcre.c)
--FILE--
<?php
echo preg_replace('//', 'X', 'abc'), "\n";
echo preg_replace('//u', 'Y', 'ab'), "\n";
?>
--EXPECT--
XaXbXcX
YaYbY
