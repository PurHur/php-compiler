--TEST--
stdlib extension_loaded/get_extension_funcs/phpversion registry (#9050, ext/standard/info.c)
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('standard') ? "std_loaded\n" : "std_missing\n";
echo extension_loaded('spl') ? "spl_loaded\n" : "spl_missing\n";
echo extension_loaded('openssl') ? "ossl_loaded\n" : "ossl_missing\n";
echo extension_loaded('json') ? "json_loaded\n" : "json_missing\n";
echo extension_loaded('pcre') ? "pcre_loaded\n" : "pcre_missing\n";
echo extension_loaded('zlib') ? "zlib_loaded\n" : "zlib_missing\n";
echo extension_loaded('nonexistent_xyz_9050') ? "bad\n" : "unknown_ok\n";

$stdFuncs = get_extension_funcs('standard');
echo is_array($stdFuncs) && count($stdFuncs) > 0 ? "std_funcs\n" : "no_std_funcs\n";
$pcreFuncs = get_extension_funcs('pcre');
echo is_array($pcreFuncs) && in_array('preg_match', $pcreFuncs, true) ? "pcre_funcs\n" : "no_pcre_funcs\n";
$zlibFuncs = get_extension_funcs('zlib');
echo is_array($zlibFuncs) && in_array('gzdeflate', $zlibFuncs, true) ? "zlib_funcs\n" : "no_zlib_funcs\n";
echo get_extension_funcs('nonexistent_xyz_9050') === false ? "unknown_funcs_ok\n" : "bad\n";

echo phpversion('spl') !== false ? "spl_ver\n" : "no_spl_ver\n";
echo phpversion('Core') !== false ? "core_ver\n" : "no_core_ver\n";
echo phpversion('core') !== false ? "core_lower_ver\n" : "no_core_lower_ver\n";
echo phpversion('nonexistent_xyz_9050') === false ? "unknown_ver_ok\n" : "bad\n";
--EXPECT--
std_loaded
spl_loaded
ossl_loaded
json_loaded
pcre_loaded
zlib_loaded
unknown_ok
std_funcs
pcre_funcs
zlib_funcs
unknown_funcs_ok
spl_ver
core_ver
core_lower_ver
unknown_ver_ok
