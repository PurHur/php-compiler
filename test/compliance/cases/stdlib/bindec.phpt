--TEST--
stdlib bindec() for binary strings
--FILE--
<?php
echo bindec('0'), "\n";
echo bindec('1010'), "\n";
echo bindec('11111111'), "\n";
echo bindec('1000'), "\n";
--EXPECT--
0
10
255
8
