<?php
echo strtotime('2024-06-01'), "\n";
$base = strtotime('2024-06-01');
echo strtotime('+1 day', $base), "\n";
$mondayBase = strtotime('2024-06-03');
echo strtotime('next Monday', $mondayBase), "\n";
var_export(function_exists('strtotime'));
echo "\n";
