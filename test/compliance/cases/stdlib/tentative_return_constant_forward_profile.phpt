--TEST--
stdlib TENTATIVE_RETURN Core constant — forward PHP 8.4 profile (#18060, zend_attributes.h)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo defined('TENTATIVE_RETURN') ? 'defined=1' : 'defined=0', "\n";
echo constant('TENTATIVE_RETURN'), "\n";
$core = get_defined_constants(true)['Core'] ?? [];
echo isset($core['TENTATIVE_RETURN']) ? 'core=1' : 'core=0', "\n";
echo $core['TENTATIVE_RETURN'] ?? 'missing', "\n";
--EXPECT--
defined=1
1
core=1
1
