--TEST--
ReflectionProperty hook introspection (#7295, ext/reflection/php_reflection.c)
--FILE--
<?php
class C {
    public string $title {
        get => 'hook';
    }
}

$p = new ReflectionProperty(C::class, 'title');
var_export(method_exists($p, 'isVirtual'));
echo "\n";
var_export($p->isVirtual());
echo "\n";
var_export($p->isDynamic());
echo "\n";
var_export($p->getMangledName());
echo "\n";
var_export($p->hasHook(PropertyHookType::Get));
echo "\n";
var_export($p->hasHook(PropertyHookType::Set));
echo "\n";
$hooks = $p->getHooks();
var_export(count($hooks) >= 1);
echo "\n";
$first = array_values($hooks)[0] ?? null;
var_export($first instanceof Closure);
echo "\n";
--EXPECT--
true
true
false
'title'
true
false
true
true
