--TEST--
stdlib preg_replace() pattern compile failure returns null (#15059, ext/pcre/php_pcre.c)
--FILE--
<?php

$r = @preg_replace('/test/e', 'x', 'test');
echo var_export($r, true), "\n";
echo preg_last_error(), "\n";

$z = @preg_replace('/test/z', 'x', 'test');
echo var_export($z, true), "\n";
echo preg_last_error(), "\n";
--EXPECT--
NULL
1
NULL
1
