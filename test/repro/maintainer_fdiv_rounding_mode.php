<?php
declare(strict_types=1);

var_export(fdiv(10.0, 3.0));
echo "\n";
try {
    var_export(fdiv(10.0, 3.0, rounding_mode: RoundingMode::TowardsZero));
    echo "\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
