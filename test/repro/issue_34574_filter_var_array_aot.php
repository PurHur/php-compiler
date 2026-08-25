<?php
$r = filter_var_array(['a' => '1', 'b' => 'x'], FILTER_VALIDATE_INT);
var_export($r);
echo "\n";
$r2 = filter_var_array(['a' => '1'], ['a' => FILTER_VALIDATE_INT]);
var_export($r2);
echo "\n";
