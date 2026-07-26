--TEST--
AOT: natsort/natcasesort named array: (Zend stub; no phantom flags) (#23243)
--FILE--
<?php
$a = ['10', '2'];
natsort(array: $a);
echo implode(',', array_values($a)), "\n";
$b = ['B', 'a'];
natcasesort(array: $b);
echo implode(',', array_values($b)), "\n";
--EXPECT--
2,10
a,B
