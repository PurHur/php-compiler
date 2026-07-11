--TEST--
AOT: TENTATIVE_RETURN Core constant on forward 8.4 profile (issue #18060)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo defined('TENTATIVE_RETURN') ? '1' : '0', "\n";
echo TENTATIVE_RETURN, "\n";
echo constant('TENTATIVE_RETURN'), "\n";
--EXPECT--
1
1
1
