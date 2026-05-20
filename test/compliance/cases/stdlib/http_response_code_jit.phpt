--TEST--
stdlib http_response_code() JIT (get/set; previous code on success)
--FILE--
<?php
echo http_response_code(), "\n";
echo http_response_code(404), "\n";
echo http_response_code(), "\n";
--EXPECT--
200
200
404
