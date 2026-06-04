<?php
class C {
    public static $x = new stdClass;
    public $y = new stdClass;
}
var_export(C::$x);
echo "\n";
$c = new C();
var_export($c->y instanceof stdClass);
echo "\n";
