<?php
var_export(enum_exists('RoundingMode', false));
echo "\n";
try {
    number_format(2.5, 0, '.', '', 99);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo round(2.5, 0, RoundingMode::HalfAwayFromZero), "\n";
echo round(2.5, 0, RoundingMode::TowardsZero), "\n";
