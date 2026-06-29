--TEST--
Language: array literal with by-ref element retains keys and referenced values (#13713, Zend/zend_hash.c)
--FILE--
<?php
$b = 2;
$a = [1 => &$b];
echo array_key_exists(1, $a) ? 'exists' : 'missing', "\n";
echo count($a), "\n";
echo json_encode(array_values($a)), "\n";
echo serialize($a), "\n";
$b = 99;
echo $a[1], "\n";
--EXPECT--
exists
1
[2]
a:1:{i:1;i:2;}
99
