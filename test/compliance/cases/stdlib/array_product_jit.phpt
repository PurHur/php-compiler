--TEST--
stdlib array_product() JIT for integers and floats
--FILE--
<?php
echo array_product(array()), "\n";
echo array_product(array(1, 2, 3)), "\n";
echo array_product(array(2, 5, 2)), "\n";
echo array_product(array(1.5, 2.5)), "\n";
--EXPECT--
1
6
20
3.75
