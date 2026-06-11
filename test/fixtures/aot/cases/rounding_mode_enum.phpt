--TEST--
AOT RoundingMode enum + round() mode (#5934)
--FILE--
<?php
echo enum_exists('RoundingMode', false) ? "yes\n" : "no\n";
echo round(2.5, 0, RoundingMode::HalfAwayFromZero), "\n";
--EXPECT--
yes
3
