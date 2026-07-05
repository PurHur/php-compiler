--TEST--
AOT phpversion() Core/standard report reference PHP version (#16337)
--FILE--
<?php
declare(strict_types=1);

$core = phpversion('Core');
$bare = phpversion();
echo is_string($core) && $core === $bare ? "core_ok\n" : "core_bad\n";
echo phpversion('standard') === $bare ? "std_ok\n" : "std_bad\n";
echo $bare === PHP_VERSION ? "php_version_ok\n" : "php_version_bad\n";
--EXPECT--
core_ok
std_ok
php_version_ok
