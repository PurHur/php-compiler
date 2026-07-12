--TEST--
stdlib preg_match()/preg_match_all() empty pattern — $matches stays NULL (#17597, ext/pcre/php_pcre.c)
--FILE--
<?php
preg_match('', 'x', $m);
var_export($m);
echo "\n";
preg_match_all('', 'x', $m2);
var_export($m2);
echo "\n";
?>
--EXPECT--
NULL
NULL
