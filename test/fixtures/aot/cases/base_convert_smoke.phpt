--TEST--
AOT: base_convert() smoke (#3173)
--FILE--
<?php
echo base_convert('1010', 2, 10), "\n";
echo base_convert('ff', 16, 2), "\n";
--EXPECT--
10
11111111
