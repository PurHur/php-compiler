--TEST--
ReflectionMethod::isDeprecated() false on 8.2 reference profile (#22110, ext/reflection/php_reflection.c)
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
echo method_exists($rm, 'isDeprecated') ? 'yes' : 'no', "\n";
var_export($rm->isDeprecated());
echo "\n";
$rc = new ReflectionMethod(Control::class, 'f');
var_export($rc->isDeprecated());
echo "\n";
--EXPECT--
yes
false
false
