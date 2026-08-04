--TEST--
AOT: ReflectionProperty::isVirtual for virtual vs backed props (#27516)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class C {
    public string $v {
        get => "x";
    }
    public string $backed = "y";
}
$v = new ReflectionProperty(C::class, "v");
$b = new ReflectionProperty(C::class, "backed");
echo "v:", $v->isVirtual() ? "virtual" : "backed", "\n";
echo "b:", $b->isVirtual() ? "virtual" : "backed", "\n";
--EXPECT--
v:virtual
b:backed
