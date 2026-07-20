--TEST--
stdlib inet_pton(null) returns false (#19053, ext/standard/basic_functions.c)
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
var_export(inet_pton(null));
--EXPECT--
false
