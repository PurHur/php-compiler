--TEST--
stdlib stripos()
--FILE--
<?php
echo stripos('Hello', 'LL'), "\n";
echo stripos('Hello', 'x') == false ? 'y' : 'n', "\n";
echo stripos('Hello', 'L', 3), "\n";
--EXPECT--
2
y
3
