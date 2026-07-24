--TEST--
AOT: ARRAY_PAD_* withheld on PROFILE=8.2 (#22786)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo defined('ARRAY_PAD_LEFT') ? '1' : '0', "\n";
echo defined('ARRAY_PAD_RIGHT') ? '1' : '0', "\n";
echo defined('ARRAY_PAD_BOTH') ? '1' : '0', "\n";
echo defined('STR_PAD_LEFT') ? '1' : '0', "\n";
?>
--EXPECT--
0
0
0
1
