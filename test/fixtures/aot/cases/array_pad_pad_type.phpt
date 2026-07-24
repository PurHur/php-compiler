--TEST--
AOT: ARRAY_PAD_* defined on PROFILE=8.4 (#14993, #22786)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo defined('ARRAY_PAD_LEFT') ? '1' : '0', "\n";
echo defined('ARRAY_PAD_RIGHT') ? '1' : '0', "\n";
echo defined('ARRAY_PAD_BOTH') ? '1' : '0', "\n";
echo ARRAY_PAD_LEFT, "\n";
echo ARRAY_PAD_RIGHT, "\n";
echo ARRAY_PAD_BOTH, "\n";
?>
--EXPECT--
1
1
1
0
1
2
