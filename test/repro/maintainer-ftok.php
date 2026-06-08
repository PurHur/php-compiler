<?php

var_export(function_exists('ftok'));
echo "\n";
$key = ftok(__FILE__, 't');
var_export(is_int($key) && $key !== -1);
echo "\n";
