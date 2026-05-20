--TEST--
Compound assignment: integer += (JIT)
--FILE--
<?php
$n = 1;
$n += 2;
echo $n, "\n";
--EXPECT--
3
