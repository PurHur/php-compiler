--TEST--
AOT: strcoll() signs via libc trampoline (#27059; NestedJIT silent 0)
--FILE--
<?php
echo strcoll('a', 'b'), "\n";
echo strcoll('b', 'a'), "\n";
echo strcoll('a', 'a'), "\n";
--EXPECT--
-1
1
0
