--TEST--
AOT: define() case_insensitive third argument (issue #3711)
--FILE--
<?php
echo define('MyConst', 42, true) ? '1' : '0', "\n";
echo defined('myconst') ? '1' : '0', "\n";
echo constant('MYCONST'), "\n";
--EXPECT--
1
1
42
