--TEST--
AOT get_extension_funcs('Core') lists Zend engine builtins (#11461, ext/standard/basic_functions.c)
--FILE--
<?php
declare(strict_types=1);

$funcs = get_extension_funcs('Core');
echo is_array($funcs) && count($funcs) > 50 ? "core_count_ok\n" : "core_count_bad\n";
echo is_array($funcs) && in_array('strlen', $funcs, true) ? "core_strlen_ok\n" : "core_strlen_bad\n";
echo extension_loaded('Core') ? "core_loaded_ok\n" : "core_loaded_bad\n";
--EXPECT--
core_count_ok
core_strlen_ok
core_loaded_ok
