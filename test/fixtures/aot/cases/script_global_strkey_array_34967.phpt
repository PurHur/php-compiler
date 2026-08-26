--TEST--
AOT: top-level string-keyed array literal init (#34967, zend_hash.c)
--FILE--
<?php
$r = ['k' => 'K'];
echo $r['k'], "\n";
function f($a, $b) { echo "$a|$b\n"; }
$x = 5;
f($x + 1, $r['k']);
--EXPECT--
K
6|K
--EXPECT_EXIT--
0
