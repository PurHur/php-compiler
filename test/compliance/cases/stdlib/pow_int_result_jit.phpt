--TEST--
JIT: pow() integer result when both operands are int (issue #3678)
--FILE--
<?php
var_dump(pow(2, 3));
var_dump(pow(2.0, 3));
--EXPECT--
int(8)
float(8)
