--TEST--
Language: builtin Override attribute class exists and is internal (#5937)
--FILE--
<?php
var_export(class_exists('Override', false));
echo "\n";
var_export(class_exists('Attribute', false));
echo "\n";
var_export((new ReflectionClass('Override'))->isInternal());
echo "\n";
class Base { public function f(): void {} }
class Child extends Base {
    #[\Override]
    public function f(): void {}
}
echo "ok\n";
--EXPECT--
true
true
true
ok
