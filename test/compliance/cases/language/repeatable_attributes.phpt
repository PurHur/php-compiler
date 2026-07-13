--TEST--
Language: repeatable user attributes compile and reflect count (#18709)
--FILE--
<?php
#[Attribute]
class A {
    public function __construct(public string $v) {}
}

#[A('1')]
#[A('2')]
class C {}

echo count((new ReflectionClass(C::class))->getAttributes()), "\n";
--EXPECT--
2
