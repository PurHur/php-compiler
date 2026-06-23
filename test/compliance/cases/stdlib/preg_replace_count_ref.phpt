--TEST--
stdlib preg_replace() count by-ref argument (#10278, ext/pcre/php_pcre.c)
--FILE--
<?php
$count = 0;
$out = preg_replace('/a/', 'b', 'aaa', -1, $count);
var_dump($out, $count);
?>
--EXPECT--
string(3) "bbb"
int(3)
