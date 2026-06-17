--TEST--
stdlib http_response_code() JIT with ResponseCode enum (#7322)
--FILE--
<?php
echo http_response_code() ? 'true' : 'false', "\n";
echo http_response_code(ResponseCode::NotFound) ? 'true' : 'false', "\n";
echo http_response_code(), "\n";
--EXPECT--
false
true
404
