--TEST--
AOT: doubleval() alias of floatval
--FILE--
<?php
echo doubleval('42'), "\n";
echo doubleval(1.5), "\n";
echo doubleval(null), "\n";
--EXPECT--
42
1.5
0
