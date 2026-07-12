--TEST--
ReflectionProperty::getValue() on declaring-class get hook invokes hook not uninitialized Error (#9865, ext/reflection/php_reflection.c)
--FILE--
<?php
class C {
    public int $x {
        get => 1;
    }
}
$r = new ReflectionProperty(C::class, 'x');
var_dump($r->getValue(new C()));
echo $r->getValue(new C()), "\n";
--EXPECT--
int(1)
1
