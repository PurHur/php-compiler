--TEST--
stdlib mb_str_pad() JIT/AOT — multibyte padding on PROFILE=8.4 (#6081, #22373)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo mb_str_pad('hi', 5), "\n";
echo mb_str_pad('日', 4, '本'), "\n";
--EXPECT--
hi   
日本本本
