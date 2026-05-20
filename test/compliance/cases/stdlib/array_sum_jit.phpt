--TEST--
stdlib array_sum() JIT for integers and floats
--FILE--
<?php
echo array_sum(array()), "\n";
echo array_sum(array(1, 2, 3)), "\n";
echo array_sum(array(10, 20, 10)), "\n";
echo array_sum(array(1.5, 2.5)), "\n";
--EXPECT--
0
6
40
4
