<?php
class Box {
    public int $count;
    public $untyped;
}
$r = new ReflectionProperty(Box::class, 'count');
$b = new Box();
var_dump($r->isInitialized($b));
$b->count = 1;
var_dump($r->isInitialized($b));

$ru = new ReflectionProperty(Box::class, 'untyped');
var_dump($ru->isInitialized($b));
