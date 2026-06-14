--TEST--
stdlib bcround() JIT — compile-time literal folding (#5935)
--JIT--
--FILE--
<?php
echo bcround('2.5', 0), "\n";
echo bcround('2.5', 0, RoundingMode::HalfTowardsZero), "\n";
echo bcround('21.123', 1), "\n";
--EXPECT--
3
2
21.1
