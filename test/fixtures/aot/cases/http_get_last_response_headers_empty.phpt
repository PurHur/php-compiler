--TEST--
AOT http_get_last_response_headers() returns null without HTTP fetch (#8769, #21172, #28412)
--FILE--
<?php
$h = http_get_last_response_headers();
echo null === $h ? "null\n" : "bad\n";
http_clear_last_response_headers();
echo function_exists('get_last_response_headers') ? "alias-bad\n" : "alias-ok\n";
--EXPECT--
null
alias-ok
