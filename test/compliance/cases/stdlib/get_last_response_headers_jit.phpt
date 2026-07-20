--TEST--
stdlib get_last_response_headers() JIT — null without HTTP wrapper state (#7236, #21172)
--FILE--
<?php
var_export(get_last_response_headers());
echo "\n";
var_export(http_get_last_response_headers());
echo "\n";
--EXPECT--
NULL
NULL
