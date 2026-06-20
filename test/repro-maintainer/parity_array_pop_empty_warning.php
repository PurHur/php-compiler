<?php
error_reporting(E_ALL);
$a = [];
var_export(array_pop($a));
echo "\n";
var_export(error_get_last());
echo "\n";
$b = [];
var_export(array_shift($b));
echo "\n";
var_export(error_get_last());
echo "\n";
