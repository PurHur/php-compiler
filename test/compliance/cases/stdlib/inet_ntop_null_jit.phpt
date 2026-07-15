--TEST--
stdlib inet_ntop(null) JIT returns false (#19053, ext/standard/basic_functions.c)
--JIT--
--FILE--
<?php
var_export(inet_ntop(null));
--EXPECT--
false
