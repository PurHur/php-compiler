--TEST--
ReflectionProperty::skipLazyInitialization() — lazy ghost default without init (#7094)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Box7094 {
    public string $name = 'init';
}

$initializerRan = false;
$o = (new ReflectionClass(Box7094::class))->newLazyGhost(
    static function (Box7094 $instance) use (&$initializerRan): void {
        $initializerRan = true;
        $instance->name = 'ghost';
    }
);

$r = new ReflectionProperty(Box7094::class, 'name');
var_export(method_exists($r, 'skipLazyInitialization'));
echo "\n";

$r->skipLazyInitialization($o);
var_export($o->name);
echo "\n";
var_export($initializerRan);
echo "\n";
var_export($r->isLazy($o));
echo "\n";
--EXPECT--
true
'init'
false
false
