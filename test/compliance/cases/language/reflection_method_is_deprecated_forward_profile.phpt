--TEST--
Language: ReflectionMethod::isDeprecated() — PHP 8.4 #[\Deprecated] method (#22110, ext/reflection/php_reflection.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class BareMethodDeprecated {
    #[\Deprecated]
    public function f(): void {}
}

class Control {
    public function f(): void {}
}

$rm = new ReflectionMethod(BareMethodDeprecated::class, 'f');
var_export($rm->isDeprecated());
echo "\n";
$rc = new ReflectionMethod(Control::class, 'f');
var_export($rc->isDeprecated());
echo "\n";
--EXPECT--
true
false
