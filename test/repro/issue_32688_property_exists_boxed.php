<?php
// Repro #32688 — AOT property_exists() on a boxed instance (value-box type tag).
class C {
    public $x = 1;
    public static $s = 2;
}
$c = new C;
var_dump(property_exists($c, 'x'));
var_dump(property_exists($c, 's'));
var_dump(property_exists($c, 'missing'));
