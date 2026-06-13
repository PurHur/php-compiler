--TEST--
AOT: debug_zval_dump() string and int zval shape (#6084)
--FILE--
<?php
$a = 'hello';
debug_zval_dump($a);
$b = 42;
debug_zval_dump($b);
--EXPECT--
string(5) "hello"
int(42)
