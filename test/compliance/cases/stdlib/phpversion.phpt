--TEST--
stdlib phpversion/php_sapi_name/php_uname runtime introspection (#3174)
--FILE--
<?php
echo function_exists('phpversion') ? "pv\n" : "no\n";
echo function_exists('php_sapi_name') ? "sapi\n" : "no\n";
echo function_exists('php_uname') ? "uname\n" : "no\n";
$v = phpversion();
echo is_string($v) && $v !== '' ? "version\n" : "no\n";
echo php_sapi_name() === 'cli' ? "cli\n" : "no\n";
$s = php_uname('s');
echo is_string($s) && $s !== '' ? "os\n" : "no\n";
echo phpversion('missing_extension_xyz') === false ? "ext_false\n" : "no\n";
--EXPECT--
pv
sapi
uname
version
cli
os
ext_false
