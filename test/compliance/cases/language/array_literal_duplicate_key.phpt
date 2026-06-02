--TEST--
language: array literal duplicate keys — last element wins (Zend zend_compile.c)
--FILE--
<?php
$a = ['a' => 1, 'a' => 2];
echo $a['a'], "\n";
$b = [0 => 'first', 0 => 'last'];
echo $b[0], "\n";
?>
--EXPECT--
2
last
