<?php
/** Repro #26328 — attributes on property hooks (Zend/zend_property_hooks.c). */
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
$h = (new ReflectionProperty(Child::class, "x"))->getHook(PropertyHookType::Get);
echo count($h->getAttributes()), "\n";
echo $h->getAttributes()[0]->getName(), "\n";
$hs = (new ReflectionProperty(Child::class, "x"))->getHook(PropertyHookType::Set);
echo count($hs->getAttributes()), "\n";
echo $hs->getAttributes()[0]->getName(), "\n";
