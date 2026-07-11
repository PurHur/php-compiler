--TEST--
stdlib RoundingMode enum + round() mode (#5934)
--FILE--
<?php
var_export(enum_exists('RoundingMode', false));
echo "\n";
echo round(2.5, 0, RoundingMode::HalfAwayFromZero), "\n";
echo round(2.5, 0, RoundingMode::TowardsZero), "\n";
echo round(1.7, 0, RoundingMode::PositiveInfinity), "\n";
echo round(-1.7, 0, RoundingMode::NegativeInfinity), "\n";
try {
    number_format(2.5, 0, '.', '', 99);
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
enum Es: string { case B = 'hi'; }
try {
    round(1.0, 0, Es::B);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
true
3
2
2
-2
ArgumentCountError
round(): Argument #3 ($mode) must be of type RoundingMode|int, Es given
