--TEST--
stdlib RoundingMode enum + round() JIT (#5934)
--FILE--
<?php
echo round(2.5, 0, RoundingMode::HalfAwayFromZero), "\n";
echo round(2.5, 0, RoundingMode::TowardsZero), "\n";
--EXPECT--
3
2
