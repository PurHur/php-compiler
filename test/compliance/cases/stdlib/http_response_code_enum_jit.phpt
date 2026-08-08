--TEST--
stdlib http_response_code() JIT int-only; ResponseCode phantom (#28931, re-#7322)
--FILE--
<?php
echo enum_exists('ResponseCode', false) ? 'enum' : 'noenum', "\n";
echo http_response_code() ? 'true' : 'false', "\n";
echo http_response_code(404) ? 'true' : 'false', "\n";
echo http_response_code(), "\n";
--EXPECT--
noenum
false
true
404
