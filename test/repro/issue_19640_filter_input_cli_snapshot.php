<?php
// filter_input() must not follow live $_GET writes (#19640)
$_GET['x'] = '1';
var_export(filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT));
echo "\n";
$_GET['x'] = '99';
var_export(filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT));
echo "\n";
var_export(filter_has_var(INPUT_GET, 'x'));
echo "\n";
