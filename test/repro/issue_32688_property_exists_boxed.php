<?php
// Repro #32688 — AOT property_exists() on a boxed instance (TYPE_OBJECT|IS_REFCOUNTED).
class C {
    public $x = 1;
}
$c = new C;
var_dump(property_exists($c, 'x'));
$o = new stdClass();
$o->x = 1;
echo property_exists($o, 'x') ? '1' : '0';
echo property_exists($o, 'y') ? '1' : '0';
echo "\n";
