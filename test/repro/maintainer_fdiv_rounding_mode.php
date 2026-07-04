<?php
declare(strict_types=1);

var_export(fdiv(10.0, 3.0));
echo "\n";
var_export(fdiv(10.0, 3.0, rounding_mode: RoundingMode::TowardsZero));
echo "\n";
