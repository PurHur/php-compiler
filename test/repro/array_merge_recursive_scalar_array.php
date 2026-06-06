<?php
$a = ['color' => 'red'];
$b = ['color' => ['favorite' => 'green']];
var_export(array_merge_recursive($a, $b));
echo "\n";
