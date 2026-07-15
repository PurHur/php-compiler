--TEST--
string strcmp family — null $string2 coerces to empty string (#18346, ext/standard/string.c)
--FILE--
<?php
var_export(strcasecmp('a', null));
echo "\n";
var_export(strnatcmp('a', null));
echo "\n";
var_export(strcoll('a', null));
echo "\n";
--EXPECT--
1
1
97
