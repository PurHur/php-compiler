--TEST--
number_format() optional RoundingMode (PHP 8.4, #9438)
--EXTENSIONS--
--FILE--
<?php
if (!enum_exists('RoundingMode')) {
    echo "skip\n";
    exit(0);
}
echo number_format(1.55, 1, '.', '', RoundingMode::HalfAwayFromZero), "\n";
echo number_format(2.5, 0, '.', '', RoundingMode::HalfEven), "\n";
?>
--EXPECT--
1.6
2
