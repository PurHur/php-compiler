<?php
// Repro #31966 — AOT property_exists() on a class with a static property.
class C {
    public static $x = 1;
}
var_dump(property_exists(C::class, 'x'));
var_dump(property_exists('C', 'x'));
