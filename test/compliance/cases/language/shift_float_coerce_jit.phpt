--TEST--
Language: << and >> with float operands truncate to int (JIT, #5270)
--FILE--
<?php
var_dump(1.5 << 1);
?>
--EXPECT--
int(2)
