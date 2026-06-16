--TEST--
stdlib get_last_response_headers() JIT — empty array without HTTP wrapper state (#7236, #8769)
--FILE--
<?php
var_export(get_last_response_headers());
echo "\n";
var_export(http_get_last_response_headers());
echo "\n";
--EXPECT--
array (
)
array (
)
