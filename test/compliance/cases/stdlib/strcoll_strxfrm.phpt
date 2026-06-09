--TEST--
stdlib strcoll()/strxfrm() (#4376, ext/standard/string.c)
--FILE--
<?php
var_dump(strcoll('a', 'b') < 0);
var_dump(strcoll('a', 'a') === 0);
var_dump(strcoll('b', 'a') > 0);
$out = strxfrm('hello');
var_dump(is_string($out));
echo $out, "\n";
--EXPECT--
bool(true)
bool(true)
bool(true)
bool(true)
hello
