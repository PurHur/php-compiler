--TEST--
stdlib inet_ntop(null) returns false (#19053, ext/standard/basic_functions.c)
--FILE--
<?php
var_export(inet_ntop(null));
--EXPECT--
false
