--TEST--
AOT: http_response_code(405) returns previous code then current
--FILE--
<?php
echo http_response_code(405) ? 'true' : 'false', "\n";
echo http_response_code(), "\n";
--EXPECT--
Status: 405
true
405
--EXPECT_EXIT--
0
