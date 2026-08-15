<?php
// Compile-only (#28412): phantom alias absent; http_* still lower for AOT.
var_export(function_exists('get_last_response_headers'));
echo "\n";
var_export(http_get_last_response_headers());
echo "\n";
