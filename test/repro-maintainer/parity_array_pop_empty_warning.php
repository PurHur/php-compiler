<?php
$a = [];
@array_pop($a);
var_export(error_get_last()['message'] ?? 'no warning');
echo "\n";
var_export(array_pop($a));
echo "\n";
$b = [];
var_export(array_shift($b));
echo "\n";
