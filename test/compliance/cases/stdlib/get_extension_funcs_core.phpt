--TEST--
stdlib get_extension_funcs('Core') lists Zend engine builtins (#11461, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$funcs = get_extension_funcs('Core');
echo is_array($funcs) && count($funcs) > 50 ? "core_count_ok\n" : "core_count_bad\n";
echo is_array($funcs) && in_array('strlen', $funcs, true) ? "core_strlen_ok\n" : "core_strlen_bad\n";
echo is_array($funcs) && in_array('class_exists', $funcs, true) ? "core_class_exists_ok\n" : "core_class_exists_bad\n";
echo extension_loaded('Core') ? "core_loaded_ok\n" : "core_loaded_bad\n";
echo is_array(get_extension_funcs('core')) ? "core_lower_ok\n" : "core_lower_bad\n";
echo get_extension_funcs('nonexistent_xyz_11461') === false ? "unknown_ok\n" : "unknown_bad\n";
--EXPECT--
core_count_ok
core_strlen_ok
core_class_exists_ok
core_loaded_ok
core_lower_ok
unknown_ok
