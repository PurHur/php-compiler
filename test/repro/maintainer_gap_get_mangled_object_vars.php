<?php

declare(strict_types=1);

/** Issue #10491 — get_mangled_object_vars() on stdClass dynamic properties (ext/standard/var.c). */
$o = new stdClass();
$o->a = 1;
var_export(get_mangled_object_vars($o));
echo "\n";
var_export(get_object_vars($o) === get_mangled_object_vars($o));
echo "\n";
