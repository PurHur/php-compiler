--TEST--
stdlib RequestParseBodyException absent on 8.2 reference profile (#13124, ext/standard/http.c)
--FILE--
<?php
var_export(class_exists('RequestParseBodyException', false));
echo "\n";
--EXPECT--
false
