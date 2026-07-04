--TEST--
stdlib ini_get_all(null, false) after array dim access — second call literals (#15931, ext/standard/ini.c)
--FILE--
<?php
$all = ini_get_all(null, true);
array_keys($all['display_errors'] ?? []);
$flat = ini_get_all(null, false);
echo is_string($flat['display_errors'] ?? null) ? "flat string\n" : "flat not string\n";
?>
--EXPECT--
flat string
