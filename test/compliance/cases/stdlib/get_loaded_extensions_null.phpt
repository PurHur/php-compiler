--TEST--
get_loaded_extensions(null) coerces to false — php-src Z_PARAM_BOOL (#18971)
--FILE--
<?php
$ext = get_loaded_extensions(null);
echo count($ext) >= 2 ? "count\n" : "no\n";
echo in_array('standard', $ext, true) ? "has_standard\n" : "no\n";
echo count(get_loaded_extensions(true)) === 0 ? "true_empty\n" : "no\n";
echo in_array('standard', get_loaded_extensions(false), true) ? "false_has_standard\n" : "no\n";
--EXPECT--
count
has_standard
true_empty
false_has_standard
