--TEST--
ReflectionProperty::hasHooks() on hooked and plain properties (ext/reflection/php_reflection.c)
--FILE--
<?php
class Box {
    public string $label {
        get => strtoupper($this->label);
        set (string $v) { $this->label = $v; }
    }
    private string $label = 'hi';
    public int $plain = 1;
}

$hooked = new ReflectionProperty(Box::class, 'label');
$plain = new ReflectionProperty(Box::class, 'plain');
echo method_exists($hooked, 'hasHooks') ? "method-yes\n" : "method-no\n";
var_export($hooked->hasHooks());
echo "\n";
var_export($hooked->hasHook(PropertyHookType::Get));
echo "\n";
var_export($hooked->hasHook(PropertyHookType::Set));
echo "\n";
var_export($plain->hasHooks());
echo "\n";
--EXPECT--
method-yes
true
true
true
false
