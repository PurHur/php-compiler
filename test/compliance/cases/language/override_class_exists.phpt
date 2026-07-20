--TEST--
Language: builtin Override attribute class exists and is internal (#5937)
--SKIPIF--
<?php
if (PHP_VERSION_ID < 80300) {
    echo "skip — host PHP < 8.3 (Override class unavailable)\n";
}
?>
--FILE--
<?php
var_export(class_exists('Override', false));
echo "\n";
var_export(class_exists('Attribute', false));
echo "\n";
var_export((new ReflectionClass('Override'))->isInternal());
echo "\n";
class Base { public function f(): string { return 'b'; } }
class Child extends Base {
    #[\Override]
    public function f(): string { return 'c'; }
}
echo (new Child())->f() . "\n";
--EXPECT--
true
true
true
c
