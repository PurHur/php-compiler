<?php
$a = ['keep' => 1, 'drop' => 2];
$out = array_filter(
    $a,
    fn ($v, $k) => $k === 'keep',
    ARRAY_FILTER_USE_BOTH
);
var_export($out);
