--TEST--
AOT: define() case_insensitive third argument ignored (issue #4052)
--FILE--
<?php
echo define('MyConst', 42, true) ? '1' : '0', "\n";
echo defined('myconst') ? '1' : '0', "\n";
--EXPECT--
1
0
