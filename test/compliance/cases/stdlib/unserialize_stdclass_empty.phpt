--TEST--
stdlib unserialize() empty stdClass O: payload (#5140, var_unserializer.c)
--FILE--
<?php
$o = unserialize('O:8:"stdClass":0:{}');
var_export($o instanceof stdClass);
echo "\n";
var_export(get_class($o) === 'stdClass');
echo "\n";
var_export(get_object_vars($o) === []);
echo "\n";
--EXPECT--
true
true
true
