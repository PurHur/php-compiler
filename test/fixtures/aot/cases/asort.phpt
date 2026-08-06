--TEST--
AOT: asort() string-key hashtable (#27513)
--FILE--
<?php
$a = array('b' => 2, 'a' => 1, 'c' => 3);
asort($a);
echo implode(',', array_keys($a)), '|', implode(',', array_values($a)), "\n";
--EXPECT--
a,b,c|1,2,3
