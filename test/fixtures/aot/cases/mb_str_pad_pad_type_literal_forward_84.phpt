--TEST--
AOT: mb_str_pad() pad_type literal int and STR_PAD_BOTH (#27435)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo mb_str_pad('测', 5, 'x', 2), "\n";
echo mb_str_pad('测', 5, 'x', STR_PAD_BOTH), "\n";
$t = 2;
echo mb_str_pad('测', 5, 'x', $t), "\n";
--EXPECT--
xx测xx
xx测xx
xx测xx
