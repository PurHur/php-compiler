<?php
// Compile-only (#7236): http_get_last_response_headers lowering for AOT.
var_export(get_last_response_headers());
echo "\n";
var_export(http_get_last_response_headers());
echo "\n";
