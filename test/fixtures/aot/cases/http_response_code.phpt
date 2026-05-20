--TEST--
AOT http_response_code() emits Status CGI line when non-default
--FILE--
<?php
http_response_code(404);
echo 'nf';
--EXPECT--
Status: 404
nf
