--TEST--
file_put_contents() LOCK_EX on php://memory returns false (#18490, ext/standard/file.c)
--FILE--
<?php
var_export(@file_put_contents('php://memory', 'x', LOCK_EX));
--EXPECT--
false
