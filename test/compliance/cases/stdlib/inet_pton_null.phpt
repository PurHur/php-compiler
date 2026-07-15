--TEST--
stdlib inet_pton(null) returns false (#19053, ext/standard/basic_functions.c)
--FILE--
<?php
var_export(inet_pton(null));
--EXPECT--
false
