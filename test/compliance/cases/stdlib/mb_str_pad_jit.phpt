--TEST--
stdlib mb_str_pad() JIT/AOT — multibyte padding (#6081)
--FILE--
<?php
echo mb_str_pad('hi', 5), "\n";
echo mb_str_pad('日', 4, '本'), "\n";
--EXPECT--
hi   
日本本本
