--TEST--
Stdlib: ini_set('default_charset') returns previous value and updates ini_get (#12531, ext/standard/ini.c)
--FILE--
<?php
$prev = ini_set('default_charset', 'UTF-8');
echo false === $prev ? "set-fail\n" : "set-ok\n";
echo ini_get('default_charset'), "\n";
$next = ini_set('default_charset', 'ISO-8859-1');
echo $next, "\n";
echo ini_get('default_charset'), "\n";
--EXPECT--
set-ok
UTF-8
UTF-8
ISO-8859-1
