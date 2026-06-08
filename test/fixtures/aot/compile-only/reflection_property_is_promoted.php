<?php
// Compile-only (#7383): promoted properties + ReflectionProperty::isPromoted() metadata path.
class C7383 {
    public function __construct(public int $a) {}
    public int $b;
}
$p = new ReflectionProperty(C7383::class, 'a');
var_export($p->isPromoted());
echo "\n";
$p = new ReflectionProperty(C7383::class, 'b');
var_export($p->isPromoted());
echo "\n";
