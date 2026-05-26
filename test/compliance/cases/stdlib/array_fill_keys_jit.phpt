--TEST--
JIT: array_fill_keys()
--FILE--
<?php
$a = array_fill_keys(array('foo', 'bar'), 'baz');
echo $a['foo'], '|', $a['bar'], "\n";
$b = array_fill_keys(array(0, 1), 'x');
echo $b[0], '|', $b[1], "\n";
$c = array_fill_keys(array('a', 'b'), 42);
echo $c['a'], '|', $c['b'], "\n";
--EXPECT--
baz|baz
x|x
42|42
