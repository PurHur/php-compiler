--TEST--
function_exists/class_exists/ini_* null coerces when caller is non-strict (#16526, ext/standard/basic_functions.c)
--FILE--
<?php
// No declare(strict_types=1) — Zend coerces null to "" for string internal params.
var_dump(function_exists(null));
var_dump(class_exists(null));
var_dump(extension_loaded(null));
var_dump(ini_get(null) === false);
var_dump(get_cfg_var(null) === false);
var_dump(ini_set(null, '1') === false);
?>
--EXPECT--
bool(false)
bool(false)
bool(false)
bool(true)
bool(true)
bool(true)
