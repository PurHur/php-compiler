--TEST--
stdlib intval() JIT with string concat
--FILE--
<?php
echo intval(3.9), "\n";
echo intval(true), "\n";
echo intval(false), "\n";
--EXPECT--
3
1
0
