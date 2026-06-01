--TEST--
language: array literal int and numeric-string keys collide (#4151, Zend zend_hash.c)
--FILE--
<?php
$a = ['123' => 1, 123 => 2];
echo $a[123], "\n";
$b = [123 => 1, '123' => 2];
echo $b[123], "\n";
$c = ['01' => 'leading', 1 => 'int'];
echo $c[1], "\n";
echo array_key_exists('01', $c) ? 'y' : 'n', "\n";
?>
--EXPECT--
2
2
int
y
