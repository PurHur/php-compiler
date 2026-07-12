--TEST--
stdlib preg_match() null subject — 0 not TypeError (#18376, ext/pcre/php_pcre.c)
--FILE--
<?php
var_export(preg_match('/a/', null));
echo "\n";
?>
--EXPECT--
0
