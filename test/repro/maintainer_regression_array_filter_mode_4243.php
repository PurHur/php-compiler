<?php

declare(strict_types=1);

$a = ['keep' => 1, 'drop' => 2];
$out = array_filter(
    $a,
    fn ($v, $k) => $k === 'keep',
    ARRAY_FILTER_USE_BOTH
);
var_export($out);
echo "\n";

$outKey = array_filter(
    $a,
    fn ($k) => $k === 'keep',
    ARRAY_FILTER_USE_KEY
);
var_export($outKey);
echo "\n";
