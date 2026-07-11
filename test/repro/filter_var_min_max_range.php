<?php

declare(strict_types=1);

$intBelowMin = filter_var('5', FILTER_VALIDATE_INT, ['options' => ['min_range' => 10]]);
$intAboveMax = filter_var('15', FILTER_VALIDATE_INT, ['options' => ['max_range' => 10]]);
$floatBelowMin = filter_var('1.5', FILTER_VALIDATE_FLOAT, ['options' => ['min_range' => 2.0]]);
$floatAboveMax = filter_var('2.5', FILTER_VALIDATE_FLOAT, ['options' => ['max_range' => 2.0]]);

echo 'int_below_min='.var_export($intBelowMin, true)."\n";
echo 'int_above_max='.var_export($intAboveMax, true)."\n";
echo 'float_below_min='.var_export($floatBelowMin, true)."\n";
echo 'float_above_max='.var_export($floatAboveMax, true)."\n";
