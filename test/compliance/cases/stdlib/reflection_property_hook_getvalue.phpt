--TEST--
ReflectionProperty::getValue() on inherited hooked property invokes get hook (ext/reflection/php_reflection.c, #9794)
--FILE--
<?php
class Base {
    public string $label {
        get => 'from-hook';
    }
}
class Child extends Base {}
$o = new Child();
$rp = new ReflectionProperty(Base::class, 'label');
echo $rp->getValue($o), "\n";
echo $o->label, "\n";
--EXPECT--
from-hook
from-hook
