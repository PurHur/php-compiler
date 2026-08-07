<?php

class Box {
    public string $name = 'init';
}

$initializerRan = false;
$o = (new ReflectionClass(Box::class))->newLazyGhost(
    static function (Box $instance) use (&$initializerRan): void {
        $initializerRan = true;
        $instance->name = 'ghost';
    }
);

$r = new ReflectionProperty(Box::class, 'name');
var_export(method_exists($r, 'skipLazyInitialization'));
echo "\n";

$r->skipLazyInitialization($o);
var_export($o->name);
echo "\n";
var_export($initializerRan);
echo "\n";
var_export($r->isLazy($o));
echo "\n";
