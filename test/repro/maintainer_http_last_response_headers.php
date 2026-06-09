<?php
// Repro for #7236 / #7024 — HTTP wrapper response header introspection.
var_export(function_exists('get_last_response_headers'));
echo "\n";
var_export(function_exists('http_get_last_response_headers'));
echo "\n";
