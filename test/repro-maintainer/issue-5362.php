<?php
class C {
    public $y = new stdClass;
}
$c = new C();
var_export($c->y instanceof stdClass);
echo "\n";
