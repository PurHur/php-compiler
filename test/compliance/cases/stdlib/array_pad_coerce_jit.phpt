--TEST--
JIT: array_pad() numeric-string and float length coercion (#4269)
--FILE--
<?php
declare(strict_types=1);

$a = array_pad([1, 2], '5', 'x');
echo count($a), ':', $a[4], "\n";
$b = array_pad([1, 2], 5.7, 'y');
echo count($b), ':', $b[4], "\n";
--EXPECT--
5:x
5:y
