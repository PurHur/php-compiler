--TEST--
stdlib inet_ntop(null) JIT returns false (#19053, ext/standard/basic_functions.c)
--JIT--
--FILE--
<?php
error_reporting(E_ALL & ~E_DEPRECATED);
var_export(inet_ntop(null));
--EXPECT--
false
