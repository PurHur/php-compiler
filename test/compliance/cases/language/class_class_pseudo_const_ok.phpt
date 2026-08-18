--TEST--
Language: Foo::class pseudo-constant remains legal (#32251, Zend/zend_compile.c)
--FILE--
<?php
class Foo {}
echo Foo::class, "\n";
echo Foo::CLASS, "\n";
--EXPECT--
Foo
Foo
