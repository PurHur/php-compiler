--TEST--
stdlib http_clear_last_response_headers() JIT — void no-op without HTTP wrapper state (#7024)
--FILE--
<?php
http_clear_last_response_headers();
var_export(http_get_last_response_headers());
echo "\n";
--EXPECT--
NULL
