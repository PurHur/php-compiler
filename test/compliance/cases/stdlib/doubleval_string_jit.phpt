--TEST--
stdlib doubleval() for strings (JIT)
--FILE--
<?php
echo doubleval('3.14'), "\n";
echo doubleval('0'), "\n";
echo doubleval(''), "\n";
--EXPECT--
3.14
0
0
