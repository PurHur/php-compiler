--TEST--
stdlib debug_zval_dump() — string and int zval shape (#6576, #22716)
--FILE--
<?php
$a = 'hello';
debug_zval_dump($a);
$b = 42;
debug_zval_dump($b);
--EXPECT--
string(5) "hello" interned
int(42)
