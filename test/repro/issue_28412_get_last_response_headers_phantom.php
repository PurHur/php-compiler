<?php
// Repro #28412 — run with PHP_COMPILER_PROFILE=8.4 (env must be set before process start).
// Expect: false / true / true
var_export(function_exists('get_last_response_headers'));
echo PHP_EOL;
var_export(function_exists('http_get_last_response_headers'));
echo PHP_EOL;
var_export(function_exists('http_clear_last_response_headers'));
echo PHP_EOL;
