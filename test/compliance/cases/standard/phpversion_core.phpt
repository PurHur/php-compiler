--TEST--
stdlib phpversion() Core/standard report reference PHP version (#16337, ext/standard/info.c)
--FILE--
<?php
declare(strict_types=1);

$core = phpversion('Core');
$lower = phpversion('core');
$std = phpversion('standard');
$bare = phpversion();
echo is_string($core) && $core === $bare ? "core_ok\n" : "core_bad\n";
echo is_string($lower) && $lower === $bare ? "lower_ok\n" : "lower_bad\n";
echo is_string($std) && $std === $bare ? "std_ok\n" : "std_bad\n";
echo $bare === PHP_VERSION ? "php_version_ok\n" : "php_version_bad\n";
echo version_compare($bare, '8.2.0', '>=') ? "ge_82\n" : "no\n";
--EXPECT--
core_ok
lower_ok
std_ok
php_version_ok
ge_82
