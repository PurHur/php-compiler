--TEST--
stdlib base_convert() arbitrary-base conversion
--FILE--
<?php
echo base_convert('1010', 2, 10), "\n";
echo base_convert('10', 10, 16), "\n";
echo base_convert('A', 16, 10), "\n";
echo base_convert('ff', 16, 2), "\n";
echo base_convert('0', 10, 36), "\n";
echo base_convert('z', 36, 10), "\n";
--EXPECT--
10
a
10
11111111
0
35
