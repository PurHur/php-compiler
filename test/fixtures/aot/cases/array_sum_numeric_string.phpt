--TEST--
AOT: array_sum() / array_product() numeric-string elements (#3619)
--FILE--
<?php
echo array_sum(array('1', '2', '3')), "\n";
echo array_product(array('2', '3')), "\n";
echo array_sum(array('1', '2.5')), "\n";
--EXPECT--
6
6
3.5
