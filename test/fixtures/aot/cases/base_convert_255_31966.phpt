--TEST--
AOT: base_convert() must verify and match Zend (#31966)
--FILE--
<?php
var_dump(base_convert(255, 10, 2));
--EXPECT--
string(8) "11111111"
--EXPECT_EXIT--
0
