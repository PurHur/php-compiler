--TEST--
JIT: shuffle() packed list (#2310)
--FILE--
<?php
$a = array();
$a[] = 'c';
$a[] = 'a';
$a[] = 'b';
shuffle($a);
sort($a);
echo implode(',', $a), "\n";
--EXPECT--
a,b,c
