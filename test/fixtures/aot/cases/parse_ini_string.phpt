--TEST--
AOT parse_ini_string() — compile-time INI literal (#3263)
--FILE--
<?php
$ini = "k=v\na=b\n";
$parsed = parse_ini_string($ini);
echo $parsed['k'], ':', $parsed['a'], "\n";
--EXPECT--
v:b
