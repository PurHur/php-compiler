--TEST--
AOT: array_fill_keys() for string and integer keys
--FILE--
<?php
$a = array_fill_keys(array('foo', 'bar'), 'baz');
echo $a['foo'], '|', $a['bar'], "\n";
$b = array_fill_keys(array(0, 1), 'x');
echo $b[0], '|', $b[1], "\n";
--EXPECT--
baz|baz
x|x
