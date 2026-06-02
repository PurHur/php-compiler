--TEST--
Language: JIT $object::class — expression object operand (#4179, #4241)
--FILE--
<?php
class Foo {}
$o = new Foo();
echo $o::class, "\n";
echo Foo::class, "\n";
--EXPECT--
Foo
Foo
