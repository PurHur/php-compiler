--TEST--
stdlib ReflectionProperty::isDynamic() on 8.4 forward profile (#7295, ext/reflection/php_reflection.c)
--FILE--
<?php
class ReflectionDynamicForwardProbe {
    public int $declared = 1;
}

$o = new stdClass();
$o->x = 42;
$p = new ReflectionProperty($o, 'x');
echo 'isDynamic=', method_exists($p, 'isDynamic') ? 'yes' : 'no', "\n";
echo var_export($p->isDynamic(), true), "\n";

$c = new ReflectionProperty(ReflectionDynamicForwardProbe::class, 'declared');
echo var_export($c->isDynamic(), true), "\n";
--EXPECT--
isDynamic=yes
true
false
