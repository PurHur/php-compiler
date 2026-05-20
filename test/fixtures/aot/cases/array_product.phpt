--TEST--
AOT: array_product() for packed lists
--FILE--
<?php
echo array_product(array()), "\n";
echo array_product(array(1, 2, 3)), "\n";
echo array_product(array(1.5, 2.5)), "\n";
--EXPECT--
1
6
3.75
