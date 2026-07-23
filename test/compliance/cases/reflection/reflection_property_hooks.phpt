--TEST--
ReflectionProperty hook introspection (#7295, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
$isRm = $first instanceof ReflectionMethod;
var_export($isRm);
echo "\n";
echo is_object($first) ? $first->getName() : 'missing';
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
$title::get
