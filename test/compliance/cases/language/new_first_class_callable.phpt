--TEST--
Language: new Class(...) first-class callable (#9767, zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class Box {
    public function __construct(public int $v) {}
}

$maker = new Box(...);
var_dump($maker(42)->v);
--EXPECT--
int(42)
