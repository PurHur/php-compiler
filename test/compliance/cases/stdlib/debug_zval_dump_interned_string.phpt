--TEST--
stdlib debug_zval_dump() — interned string marker (#22716)
--FILE--
<?php
$a = 'hi';
debug_zval_dump($a);
$b = 'hello';
debug_zval_dump($b);
$c = 42;
debug_zval_dump($c);
--EXPECT--
string(2) "hi" interned
string(5) "hello" interned
int(42)
