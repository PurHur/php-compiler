--TEST--
stdlib array_shift()
--FILE--
<?php
$a = array(10, 20, 30);
echo array_shift($a), "\n";
echo count($a), "\n";
echo array_shift($a), "\n";
echo array_shift($a), "\n";
echo array_shift($a) === null ? 'y' : 'n', "\n";
--EXPECT--
PHP Warning:  array_shift(): Trying to shift an empty array
10
2
20
30
y
