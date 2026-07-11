--TEST--
stdlib str_replace()/preg_replace() null subject coerces (#11938, ext/standard/string.c, ext/pcre/php_pcre.c)
--FILE--
<?php
echo str_replace('a', 'b', null), "\n";
echo preg_replace('/a/', 'b', null), "\n";
?>
--EXPECT--

