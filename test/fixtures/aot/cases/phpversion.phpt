--TEST--
AOT phpversion/php_sapi_name/php_uname (issue #3174)
--FILE--
<?php
echo function_exists('phpversion') ? "pv\n" : "no\n";
echo function_exists('php_sapi_name') ? "sapi\n" : "no\n";
echo function_exists('php_uname') ? "uname\n" : "no\n";
$v = phpversion();
echo strlen($v) > 0 ? "version\n" : "no\n";
echo php_sapi_name() === 'cli' ? "cli\n" : "no\n";
$s = php_uname('s');
echo strlen($s) > 0 ? "os\n" : "no\n";
--EXPECT--
pv
sapi
uname
version
cli
os
