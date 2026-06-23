<?php

declare(strict_types=1);

// Issue #10719 — get_object_vars() on internal objects from global scope (ext/standard/var.c).

var_export(get_object_vars(new Exception('x')));
echo "\n";
var_export(get_object_vars(new DateTime('2020-01-01')));
echo "\n";

class Box {
    public $x = 1;
}

var_export(get_object_vars(new Box()));
echo "\n";

$o = new stdClass();
$o->a = 1;
var_export(get_object_vars($o));
echo "\n";
