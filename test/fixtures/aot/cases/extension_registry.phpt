--TEST--
AOT extension_loaded/get_extension_funcs/phpversion registry (#9050, ext/standard/info.c)
--FILE--
<?php
declare(strict_types=1);

echo extension_loaded('standard') ? "std_loaded\n" : "std_missing\n";
echo extension_loaded('spl') ? "spl_loaded\n" : "spl_missing\n";
echo extension_loaded('openssl') ? "ossl_loaded\n" : "ossl_missing\n";
echo extension_loaded('pcre') ? "pcre_loaded\n" : "pcre_missing\n";
echo extension_loaded('zlib') ? "zlib_loaded\n" : "zlib_missing\n";
echo get_extension_funcs('nonexistent_xyz_9050') === false ? "unknown_funcs_ok\n" : "bad\n";
echo phpversion('spl') !== false ? "spl_ver\n" : "no_spl_ver\n";
--EXPECT--
std_loaded
spl_loaded
ossl_loaded
pcre_loaded
zlib_loaded
unknown_funcs_ok
spl_ver
