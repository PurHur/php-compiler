--TEST--
stdlib get_extension_funcs() lists in-tree extension builtins (#3433, ext/standard/basic_functions.c)
--FILE--
<?php
echo (int) function_exists('get_extension_funcs'), "\n";
$funcs = get_extension_funcs('hash');
echo count($funcs), "\n";
echo (int) in_array('hash_init', $funcs, true), "\n";
$json = get_extension_funcs('json');
echo (int) in_array('json_encode', $json, true), "\n";
echo (int) (get_extension_funcs('nonexistent_xyz_3433') === false), "\n";
--EXPECT--
1
8
1
1
1
