--TEST--
Language: comparison inside function call argument (#13694, zend_compile.c)
--FILE--
<?php
var_dump(var_dump(1) !== false);
$r = (1 !== 0);
var_dump($r);
?>
--EXPECT--
int(1)
bool(true)
bool(true)
