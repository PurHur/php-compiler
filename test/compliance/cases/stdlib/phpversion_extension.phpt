--TEST--
stdlib phpversion($extension) returns extension module version (#10969, ext/standard/info.c)
--FILE--
<?php
declare(strict_types=1);

$core = phpversion();
$pcre = phpversion('pcre');
echo $pcre !== false && $pcre !== $core ? "pcre_diff\n" : "pcre_same\n";
echo phpversion('core') === $core ? "core_ok\n" : "core_bad\n";
echo phpversion('nonexistent_xyz_10969') === false ? "unknown_ok\n" : "unknown_bad\n";
--EXPECT--
pcre_same
core_ok
unknown_ok
