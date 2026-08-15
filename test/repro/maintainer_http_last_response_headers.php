<?php
// Repro for #7236 / #7024 / #28412 — HTTP wrapper response header introspection.
var_export(function_exists('get_last_response_headers'));
echo "\n";
var_export(function_exists('http_get_last_response_headers'));
echo "\n";
var_export(function_exists('http_clear_last_response_headers'));
echo "\n";
@file_get_contents('http://example.com');
var_export(http_get_last_response_headers() !== null);
echo "\n";
http_clear_last_response_headers();
var_export(http_get_last_response_headers());
echo "\n";
