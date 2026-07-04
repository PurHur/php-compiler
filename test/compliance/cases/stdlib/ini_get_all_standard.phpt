--TEST--
stdlib ini_get_all() per-extension filtering (#9052, ext/standard/ini.c)
--FILE--
<?php
$std = ini_get_all('standard', true);
echo is_array($std) ? 'std_array '.count($std)."\n" : "std_fail\n";
echo isset($std['user_agent']) ? "std_user_agent\n" : "std_no_user_agent\n";
echo isset($std['unserialize_max_depth']) ? "std_unserialize_max_depth\n" : "std_no_unserialize_max_depth\n";

$pcre = ini_get_all('pcre', true);
echo is_array($pcre) ? 'pcre_array '.count($pcre)."\n" : "pcre_fail\n";
echo isset($pcre['pcre.jit']) ? "pcre_jit\n" : "pcre_no_jit\n";

$flat = ini_get_all('standard', false);
echo is_string($flat['user_agent'] ?? null) ? "std_flat_ok\n" : "std_flat_fail\n";

echo ini_get_all('no_such_ext_xyz', true) === false ? "unknown_false\n" : "unknown_bad\n";
--EXPECT--
PHP Warning:  ini_get_all(): Extension "no_such_ext_xyz" cannot be found in - on line 14
std_array 14
std_user_agent
std_unserialize_max_depth
pcre_array 3
pcre_jit
std_flat_ok
unknown_false
