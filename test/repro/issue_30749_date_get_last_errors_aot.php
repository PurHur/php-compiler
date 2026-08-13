<?php
// Repro #30749 — date_get_last_errors / DateTime::getLastErrors after failed createFromFormat.
var_export(date_get_last_errors());
echo "\n";
date_create_from_format('Y-m-d', 'not-a-date');
var_export(date_get_last_errors()['error_count'] > 0);
echo "\n";
DateTime::createFromFormat('Y-m-d', 'not-a-date');
$bag = DateTime::getLastErrors();
var_export(is_array($bag) && $bag['error_count'] > 0);
echo "\n";
