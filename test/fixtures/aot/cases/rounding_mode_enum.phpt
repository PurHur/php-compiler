--TEST--
AOT RoundingMode enum + round() mode (#5934, #26800)
--FILE--
<?php
echo enum_exists('RoundingMode', false) ? "yes\n" : "no\n";
echo round(2.5, 0, RoundingMode::HalfAwayFromZero), "\n";
echo round(1.5, 0, RoundingMode::HalfAwayFromZero), "\n";
echo round(-1.5, 0, RoundingMode::HalfAwayFromZero), "\n";
echo round(1.55, 1, RoundingMode::HalfEven), "\n";
echo round(1.5), "\n";
--EXPECT--
yes
3
2
-2
1.6
2
