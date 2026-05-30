--TEST--
Language: array union operator + (Zend add_function / zend_hash_merge parity, #3690)
--FILE--
<?php
$r = ['a' => 1, 'c' => 3] + ['b' => 2, 'a' => 9];
echo $r['a'], "\n";
echo $r['b'], "\n";
echo $r['c'], "\n";
echo isset($r['a']) ? 1 : 0, "\n";
--EXPECT--
1
2
3
1
