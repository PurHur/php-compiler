--TEST--
Language: builtin NoDiscard attribute class exists and is internal (#6992)
--FILE--
<?php
var_export(class_exists('NoDiscard', false));
echo "\n";
var_export(class_exists('Attribute', false));
echo "\n";
var_export((new ReflectionClass('NoDiscard'))->isInternal());
echo "\n";
var_export(attribute_exists(Attribute::class, NoDiscard::class));
echo "\n";
#[\NoDiscard]
function f(): int {
    return 42;
}
echo f(), "\n";
--EXPECT--
true
true
true
true
42
