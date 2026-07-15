--TEST--
stdlib inet_pton(null) JIT returns false (#19053, ext/standard/basic_functions.c)
--JIT--
--FILE--
<?php
var_export(inet_pton(null));
--EXPECT--
false
