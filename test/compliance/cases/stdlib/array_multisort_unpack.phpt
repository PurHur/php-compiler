--TEST--
stdlib array_multisort() call-time unpack (php-src ext/standard/array.c, #6689)
--FILE--
<?php
$cols = array(array(3, 1, 2), array('c', 'a', 'b'));
array_multisort(...$cols);
echo $cols[0][0], "\n";
echo $cols[0][2], "\n";
echo $cols[1][0], "\n";
echo $cols[1][2], "\n";
--EXPECT--
1
3
a
c
