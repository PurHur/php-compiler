--TEST--
ReflectionClass::getStaticPropertyValue() on static get hook invokes hook (#9863, ext/reflection/php_reflection.c)
--FILE--
<?php
class C {
    public static int $x {
        get => 99;
    }
}
$rc = new ReflectionClass(C::class);
var_export($rc->getStaticPropertyValue('x'));
echo "\n";
$rp = new ReflectionProperty(C::class, 'x');
var_export($rp->getValue());
echo "\n";
--EXPECT--
99
99
