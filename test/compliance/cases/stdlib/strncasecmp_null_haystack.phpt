--TEST--
stdlib strncasecmp() null haystack — -1 not 0 (#18700, ext/standard/string.c)
--FILE--
<?php
var_export(strncasecmp(null, 'a', 1));
echo "\n";
var_export(strncasecmp('', 'a', 1));
echo "\n";
var_export(strncasecmp('a', null, 1));
echo "\n";
var_export(strncasecmp('ab', 'ABC', 3));
echo "\n";
--EXPECT--
-1
-1
1
-1
