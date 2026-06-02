--TEST--
AOT: $variable::class dynamic class operand (#4179)
--FILE--
<?php
class Foo {}
$c = 'Foo';
echo $c::class, "\n";
echo Foo::class, "\n";
--EXPECT--
Foo
Foo
