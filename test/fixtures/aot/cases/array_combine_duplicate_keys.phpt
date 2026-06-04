--TEST--
AOT: array_combine() duplicate keys keep last value
--FILE--
<?php
$c = array_combine([1, 1], ['a', 'b']);
echo $c[1], "\n";
$d = array_combine(['k', 'k'], ['a', 'b']);
echo $d['k'], "\n";
--EXPECT--
b
b
