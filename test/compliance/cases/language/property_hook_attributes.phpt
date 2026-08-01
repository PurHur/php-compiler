--TEST--
Language: attributes on property hooks get/set (#26328, Zend/zend_property_hooks.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
#[Attribute]
class Marker {}
class Base {
    public string $x {
        get => "base";
        set {}
    }
}
class Child extends Base {
    public string $x {
        #[\Override]
        get => "child";
        #[Marker]
        set {}
    }
}
echo (new Child)->x, "\n";
$get = (new ReflectionProperty(Child::class, "x"))->getHook(PropertyHookType::Get);
echo count($get->getAttributes()), "\n";
echo $get->getAttributes()[0]->getName(), "\n";
$set = (new ReflectionProperty(Child::class, "x"))->getHook(PropertyHookType::Set);
echo count($set->getAttributes()), "\n";
echo $set->getAttributes()[0]->getName(), "\n";
--EXPECT--
child
1
Override
1
Marker
