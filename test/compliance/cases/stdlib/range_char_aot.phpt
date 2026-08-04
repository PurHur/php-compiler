--TEST--
stdlib range() char bounds under AOT/JIT (#27563, ext/standard/array.c)
--FILE--
<?php
echo implode(',', range('a', 'c')), "\n";
echo implode(',', range('z', 'x')), "\n";
echo implode(',', range('a', 'e', 2)), "\n";
--EXPECT--
a,b,c
z,y,x
a,c,e
