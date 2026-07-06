--TEST--
language RoundingMode int-backed case ->value (#16875)
--FILE--
<?php
var_export(RoundingMode::HalfAwayFromZero->value);
echo "\n";
var_export(RoundingMode::HalfAwayFromZero->name);
echo "\n";
var_export(RoundingMode::TowardsZero->value);
echo "\n";
--EXPECT--
1
'HalfAwayFromZero'
7
