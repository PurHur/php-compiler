--TEST--
stdlib http_response_code() JIT (get/set; previous code on success)
--FILE--
<?php
echo http_response_code() ? 'true' : 'false', "\n";
echo http_response_code(404) ? 'true' : 'false', "\n";
echo http_response_code(), "\n";
--EXPECT--
false
true
404
