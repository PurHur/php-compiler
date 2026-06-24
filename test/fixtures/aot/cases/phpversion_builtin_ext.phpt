--TEST--
AOT phpversion() bundled extensions return runtime version (#11162)
--FILE--
<?php
declare(strict_types=1);

$core = phpversion();
echo phpversion('pcre') === $core ? "pcre_ok\n" : "pcre_bad\n";
echo phpversion('json') === $core ? "json_ok\n" : "json_bad\n";
--EXPECT--
pcre_ok
json_ok
