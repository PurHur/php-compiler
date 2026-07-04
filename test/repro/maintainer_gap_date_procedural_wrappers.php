<?php
/**
 * Repro for #9219 — procedural date_* wrappers missing on VM.
 */
$fs = [
    'date_format',
    'date_timestamp_get',
    'date_timestamp_set',
    'date_timezone_get',
    'date_timezone_set',
    'date_get_last_errors',
];
foreach ($fs as $f) {
    echo $f, ' exists? ';
    var_dump(function_exists($f));
}
$dt = date_create('2020-01-02T03:04:05+00:00');
var_dump(date_format($dt, 'c'));
var_dump(date_timestamp_get($dt));
