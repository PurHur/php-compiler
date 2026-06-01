--TEST--
define() case_insensitive third argument ignored (issue #4052, php-src basic_functions.c)
--FILE--
<?php
echo define('MyConst', 42, true) ? '1' : '0', "\n";
echo defined('myconst') ? '1' : '0', "\n";
constant('MYCONST');
echo define('CaseSens', 7) ? '1' : '0', "\n";
echo defined('casesens') ? '1' : '0', "\n";
echo defined('CaseSens') ? '1' : '0', "\n";
--EXPECT--
1
0
--EXPECT_EXIT--
255
