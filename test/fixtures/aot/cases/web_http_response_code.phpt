--TEST--
AOT: http_response_code() get and set
--FILE--
<?php
echo http_response_code(), "\n";
echo http_response_code(404), "\n";
echo http_response_code(), "\n";
echo http_response_code(999) ? 'true' : 'false', "\n";
--EXPECT--
200
200
404
false
--EXPECT_EXIT--
0
