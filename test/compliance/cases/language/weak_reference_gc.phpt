--TEST--
language WeakReference / WeakMap — GC clears weak refs (#3282)
--FILE--
<?php
class Box {}
$obj = new Box();
$ref = WeakReference::create($obj);
echo $ref->get() !== null ? '1' : '0';
unset($obj);
gc_collect_cycles();
echo $ref->get() === null ? '1' : '0';

$map = new WeakMap();
$map->offsetSet(new Box(), 42);
gc_collect_cycles();
echo $map->count();
--EXPECT--
110
