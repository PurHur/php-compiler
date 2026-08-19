--TEST--
Language: hashtable vs hashtable <=> / < > == match zend_compare_arrays (#32524 leftover of #32501, Zend/zend_operators.c)
--FILE--
<?php
echo ['a' => 1] <=> ['a' => 2], "\n";
echo ['a' => 1] < ['a' => 2] ? "t\n" : "f\n";
echo ['a' => 2] > ['a' => 1] ? "t\n" : "f\n";
echo ['a' => 1] <=> ['a' => 1], "\n";
echo (['a' => 1] == ['a' => 1]) ? "eq\n" : "neq\n";
echo (['a' => 1] == ['a' => 2]) ? "eq\n" : "neq\n";
echo (['b' => 1, 'a' => 2] == ['a' => 2, 'b' => 1]) ? "eq\n" : "neq\n";
echo ['a' => 1, 'b' => 2] <=> ['a' => 1], "\n";
?>
--EXPECT--
-1
t
t
0
eq
neq
eq
1
