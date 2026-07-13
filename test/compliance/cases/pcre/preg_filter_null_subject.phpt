--TEST--
pcre preg_filter() null subject coerces to empty string and returns NULL (#18698, ext/pcre/php_pcre.c)
--FILE--
<?php
var_export(preg_filter('/a/', 'b', null));
?>
--EXPECT--
NULL
