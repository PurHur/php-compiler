--TEST--
stdlib array_search() strict comparison
--FILE--
<?php
$a = array(1, 2, 'x');
echo array_search(2, $a), "\n";
echo array_search('2', $a, true) === false ? 'y' : 'n', "\n";
--EXPECT--
1
y
