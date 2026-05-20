--TEST--
AOT: getallheaders() from CGI HTTP_* environment
--ENV--
REQUEST_METHOD=GET
HTTP_X_TEST=1
HTTP_HOST=example.test
--FILE--
<?php
$headers = getallheaders();
echo array_key_exists('X-Test', $headers) ? 'yes' : 'no', "\n";
echo $headers['Host'], "\n";
--EXPECT--
yes
example.test
