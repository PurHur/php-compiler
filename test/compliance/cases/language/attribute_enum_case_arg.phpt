--TEST--
Language: attribute constructor arguments accept enum case (#9988, zend_compile.c)
--FILE--
<?php
enum E: int {
    case A = 1;
}
#[SomeAttr(E::A)]
class C {}
class SomeAttr {
    public function __construct(public mixed $v) {}
}
$args = (new ReflectionClass(C::class))->getAttributes()[0]->getArguments();
echo $args[0] instanceof E ? 'enum' : 'other';
echo "\n";
echo $args[0]->name;
--EXPECT--
enum
A
