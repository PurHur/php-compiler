<?php
// Repro #33946 — CGI IF_G snapshot ignores later $_GET mutation (re-#19640)
var_export(filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT));
echo "\n";
$_GET['x'] = '99';
var_export(filter_input(INPUT_GET, 'x', FILTER_VALIDATE_INT));
echo "\n";
var_export($_GET['x']);
echo "\n";
