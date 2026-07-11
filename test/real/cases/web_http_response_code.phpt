--TEST--
Web: http_response_code() get and set
--FILE--
<?php
echo http_response_code() ? 'true' : 'false', "\n";
echo http_response_code(404) ? 'true' : 'false', "\n";
echo http_response_code(), "\n";
echo http_response_code(999) ? 'true' : 'false', "\n";
--EXPECT--
false
true
404
true
