--TEST--
stdlib http_get_last_response_headers() returns empty array before HTTP fetch (issue #8769)
--FILE--
<?php
var_export(http_get_last_response_headers());
echo "\n";
var_export(get_last_response_headers());
echo "\n";
http_clear_last_response_headers();
var_export(http_get_last_response_headers());
echo "\n";
--EXPECT--
array (
)
array (
)
array (
)
