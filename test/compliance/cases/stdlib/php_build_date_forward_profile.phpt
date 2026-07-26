--TEST--
stdlib PHP_BUILD_DATE Core constant — forward PHP 8.5 profile (#23231, main/php_version.h)
--ENV--
PHP_COMPILER_PROFILE=8.5
--FILE--
<?php
echo defined('PHP_BUILD_DATE') ? 'defined=1' : 'defined=0', "\n";
$stamp = constant('PHP_BUILD_DATE');
echo is_string($stamp) ? 'type=string' : 'type=other', "\n";
$dt = DateTimeImmutable::createFromFormat('M j Y H:i:s', $stamp);
echo false === $dt ? "parse=fail\n" : "parse=ok\n";
$core = get_defined_constants(true)['Core'] ?? [];
echo isset($core['PHP_BUILD_DATE']) ? 'core=1' : 'core=0', "\n";
echo ($core['PHP_BUILD_DATE'] ?? null) === $stamp ? "core_match=1\n" : "core_match=0\n";
--EXPECT--
defined=1
type=string
parse=ok
core=1
core_match=1
