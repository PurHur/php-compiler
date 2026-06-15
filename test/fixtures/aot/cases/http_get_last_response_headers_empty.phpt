--TEST--
AOT http_get_last_response_headers() returns empty array without HTTP fetch (#8769)
--FILE--
<?php
$h = http_get_last_response_headers();
echo is_array($h) ? "array\n" : "bad\n";
echo count($h), "\n";
http_clear_last_response_headers();
$g = get_last_response_headers();
echo is_array($g) ? "array\n" : "bad\n";
echo count($g), "\n";
--EXPECT--
array
0
array
0
