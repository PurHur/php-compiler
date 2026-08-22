<?php
// Repro #33946 — AOT filter_input must use IF_G snapshot, not live $_GET (re-#19640)
$_GET['x'] = '1';
var_export(filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT));
echo "\n";
$_GET['x'] = '99';
var_export(filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT));
echo "\n";
var_export(filter_has_var(INPUT_GET, 'x'));
echo "\n";
