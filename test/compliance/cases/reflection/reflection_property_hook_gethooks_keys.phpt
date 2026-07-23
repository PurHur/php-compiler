--TEST--
ReflectionProperty::getHooks() returns ReflectionMethod (#22491, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Box {
    public string $label {
        get => strtoupper($this->label);
        set (string $v) { $this->label = $v; }
    }
    private string $label = 'hi';
}

$rp = new ReflectionProperty(Box::class, 'label');
$hooks = $rp->getHooks();
ksort($hooks);
echo implode(',', array_keys($hooks)), "\n";
echo $hooks['get'] instanceof ReflectionMethod ? "get-rm\n" : "get-not-rm\n";
echo $hooks['set'] instanceof ReflectionMethod ? "set-rm\n" : "set-not-rm\n";
echo $hooks['get']->getName(), "\n";
echo $hooks['set']->getName(), "\n";
$getHook = $rp->getHook(PropertyHookType::Get);
echo $getHook instanceof ReflectionMethod ? "getHook-rm\n" : "getHook-not-rm\n";
echo $getHook->getName(), "\n";
$setIsRm = $rp->getHook(PropertyHookType::Set) instanceof ReflectionMethod;
var_export($setIsRm);
echo "\n";
--EXPECT--
get,set
get-rm
set-rm
$label::get
$label::set
getHook-rm
$label::get
true
