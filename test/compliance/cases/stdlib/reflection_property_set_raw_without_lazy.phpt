--TEST--
ReflectionProperty::setRawValueWithoutLazyInitialization() — lazy ghost raw write (#7095)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
class Entity7095 {
    public string $label = 'default';
}

$initializerRan = false;
$o = (new ReflectionClass(Entity7095::class))->newLazyGhost(
    static function (Entity7095 $e) use (&$initializerRan): void {
        $initializerRan = true;
        $e->label = 'initialized';
    }
);

$rp = new ReflectionProperty(Entity7095::class, 'label');
var_export(method_exists($rp, 'setRawValueWithoutLazyInitialization'));
echo "\n";

$rp->setRawValueWithoutLazyInitialization($o, 'raw');
var_export($o->label);
echo "\n";
var_export($initializerRan);
echo "\n";
var_export($rp->isLazy($o));
echo "\n";
--EXPECT--
true
'raw'
false
false
