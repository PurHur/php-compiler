--TEST--
Integration: count, array_key_exists, is_array, is_object
--FILE--
<?php
$a = array(1, 2);
echo count($a), "\n";
echo array_key_exists(0, $a) ? 'y' : 'n', "\n";
echo array_key_exists(2, $a) ? 'y' : 'n', "\n";
echo is_array($a) ? 'y' : 'n', "\n";
echo is_object($a) ? 'y' : 'n', "\n";
echo is_object(null) ? 'y' : 'n', "\n";
--EXPECT--
2
y
n
y
n
n
