--TEST--
stdlib PHP_SBINDIR withheld on PHP 8.3 profile (#28170, main/main.c)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
echo defined('PHP_SBINDIR') ? 'defined=1' : 'defined=0', "\n";
$core = get_defined_constants(true)['Core'] ?? [];
echo isset($core['PHP_SBINDIR']) ? 'core=1' : 'core=0', "\n";
--EXPECT--
defined=0
core=0
