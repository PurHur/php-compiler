<?php
// Repro for #3309 — get_headers() via VmHttpFetchNative (no host delegation).
var_export(function_exists('get_headers'));
echo "\n";
$h = get_headers('http://example.com');
var_export($h !== false);
echo "\n";
