--TEST--
stdlib apache_request_headers() alias of getallheaders() (issue #6036)
--ENV--
REQUEST_METHOD=GET
HTTP_X_TEST=1
HTTP_HOST=example.test
--FILE--
<?php
echo function_exists('apache_request_headers') ? 'yes' : 'no', "\n";
$apache = apache_request_headers();
$all = getallheaders();
echo $apache === $all ? 'same' : 'diff', "\n";
echo array_key_exists('X-Test', $apache) ? 'yes' : 'no', "\n";
echo $apache['Host'], "\n";
--EXPECT--
yes
same
yes
example.test