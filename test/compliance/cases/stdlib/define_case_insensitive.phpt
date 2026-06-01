--TEST--
define() case_insensitive third argument (issue #3711, php-src basic_functions.c)
--FILE--
<?php
echo define('MyConst', 42, true) ? '1' : '0', "\n";
echo defined('myconst') ? '1' : '0', "\n";
echo constant('MYCONST'), "\n";
echo define('CaseSens', 7) ? '1' : '0', "\n";
echo defined('casesens') ? '1' : '0', "\n";
echo defined('CaseSens') ? '1' : '0', "\n";
--EXPECT--
1
1
42
1
0
1
