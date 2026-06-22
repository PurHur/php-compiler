--TEST--
Stdlib: get_mangled_object_vars() on stdClass dynamic properties (#10491, ext/standard/var.c)
--FILE--
<?php
$o = new stdClass();
$o->a = 1;
var_export(get_mangled_object_vars($o));
echo "\n";
var_export(get_object_vars($o) === get_mangled_object_vars($o));
echo "\n";
--EXPECT--
array (
  'a' => 1,
)
true
