--TEST--
stdlib var_export($obj->method(), true) — MethodCall inline arg slot (#17251, ext/standard/var.c)
--FILE--
<?php
declare(strict_types=1);

$it = new ArrayIterator([1, 2, 3]);
$it->next();
echo 'cur=', var_export($it->current(), true), "\n";

$it->rewind();
$it->next();
echo 'key=', var_export($it->key(), true), "\n";

function g() { yield 10; yield 20; yield 30; }
$gen = g();
$gen->next();
$gen->next();
echo 'val=', var_export($gen->current(), true), "\n";
--EXPECT--
cur=2
key=1
val=30
