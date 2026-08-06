--TEST--
stdlib PHP_SBINDIR Core path constant — forward PHP 8.4 profile (#28170, main/main.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo defined('PHP_SBINDIR') ? 'defined=1' : 'defined=0', "\n";
$val = defined('PHP_SBINDIR') ? (string) constant('PHP_SBINDIR') : '';
echo '' !== $val ? 'nonempty=1' : 'nonempty=0', "\n";
$core = get_defined_constants(true)['Core'] ?? [];
echo isset($core['PHP_SBINDIR']) ? 'core=1' : 'core=0', "\n";
echo isset($core['PHP_SBINDIR']) && '' !== (string) $core['PHP_SBINDIR'] ? 'core_nonempty=1' : 'core_nonempty=0', "\n";
--EXPECT--
defined=1
nonempty=1
core=1
core_nonempty=1
