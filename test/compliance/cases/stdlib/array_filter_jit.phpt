--TEST--
stdlib array_filter() default mask JIT
--FILE--
<?php
$items = ['', 'a', '0', 'b'];
$out = array_filter($items);
echo count($out), "\n";
echo in_array('a', $out) ? 'y' : 'n', "\n";
echo in_array('b', $out) ? 'y' : 'n', "\n";
echo in_array('', $out) ? 'y' : 'n', "\n";
--EXPECT--
2
y
y
n
