<?php

var_export(array_find([1, 2, 3], fn ($v) => $v > 1));
echo "\n";
var_export(array_find_key(['a' => 1, 'b' => 2], fn ($v, $k) => $v > 1));
echo "\n";
var_export(array_find_key([1 => 'a', 2 => 'b'], fn ($v, $k) => $k > 1));
echo "\n";
