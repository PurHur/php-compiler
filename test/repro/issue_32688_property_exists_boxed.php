<?php
// Repro #32688 — AOT property_exists() on a boxed instance (TYPE_OBJECT|IS_REFCOUNTED).
class C {
    public $x = 1;
}
$c = new C;
var_dump(property_exists($c, 'x'));
$o = new stdClass();
$o->dyn = 1;
echo property_exists($o, 'dyn') ? '1' : '0';
echo property_exists($o, 'missing') ? '1' : '0';
echo "\n";
