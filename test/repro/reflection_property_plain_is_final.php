<?php
// Repro #22341 — plain final property ReflectionProperty::isFinal() under PROFILE=8.4
class C
{
    public final string $x = 'a';
}
$r = new ReflectionProperty('C', 'x');
var_export($r->isFinal());
echo PHP_EOL;
