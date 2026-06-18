--TEST--
stdlib str_pad() JIT named length:/pad_string:/pad_type: arguments (#9526, ext/standard/string.c)
--FILE--
<?php
echo str_pad('hi', length: 5), "\n";
echo str_pad('hi', length: 5, pad_string: '0'), "\n";
echo str_pad('hi', pad_type: STR_PAD_LEFT, length: 6, pad_string: '-'), "\n";
--EXPECT--
hi   
hi000
----hi
