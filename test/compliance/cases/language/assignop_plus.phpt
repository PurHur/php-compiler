--TEST--
Compound assignment: integer += (VM)
--FILE--
<?php
$n = 1;
$n += 2;
echo $n, "\n";
--EXPECT--
3
