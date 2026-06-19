--TEST--
Language: new Class(...) first-class callable must compile-fatal (#10130, Zend/zend_compile.c)
--FILE--
<?php
declare(strict_types=1);

class Box {
    public function __construct(public int $v) {}
}

$maker = new Box(...);
var_dump($maker(42)->v);
--EXPECT_EXIT--
255
--EXPECTF--
parseAndCompile failure: target=%s: Cannot create Closure for new expression
