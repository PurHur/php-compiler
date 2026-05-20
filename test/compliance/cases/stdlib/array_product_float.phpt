--TEST--
stdlib array_product() promotes to float when needed
--FILE--
<?php
echo array_product(array(2, 2.5)), "\n";
echo array_product(array(1.5, 2)), "\n";
--EXPECT--
5
3
