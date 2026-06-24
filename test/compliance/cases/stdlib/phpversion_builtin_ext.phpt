--TEST--
stdlib phpversion() bundled extensions return runtime version (#11162, ext/standard/info.c)
--FILE--
<?php
declare(strict_types=1);

$core = phpversion();
echo phpversion('pcre') === $core ? "pcre_ok\n" : "pcre_bad\n";
echo phpversion('json') === $core ? "json_ok\n" : "json_bad\n";
echo phpversion('zlib') === $core ? "zlib_ok\n" : "zlib_bad\n";
echo phpversion('standard') === $core ? "std_ok\n" : "std_bad\n";
echo phpversion('nonexistent_xyz_11162') === false ? "unknown_ok\n" : "unknown_bad\n";
--EXPECT--
pcre_ok
json_ok
zlib_ok
std_ok
unknown_ok
