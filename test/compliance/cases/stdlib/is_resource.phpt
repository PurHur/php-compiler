--TEST--
stdlib is_resource() — open/closed stream handles (#3519)
--FILE--
<?php
$f = fopen('php://memory', 'r+');
var_dump(is_resource($f));
var_dump(is_resource(null));
var_dump(is_resource(1));
fclose($f);
var_dump(is_resource($f));
--EXPECT--
bool(true)
bool(false)
bool(false)
bool(false)
