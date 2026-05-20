--TEST--
AOT: REQUEST_SCHEME and HTTP_HOST for absolute URLs (issue #235)
--ENV--
REQUEST_METHOD=GET
SCRIPT_NAME=/index.php
REQUEST_URI=/index.php
HTTP_HOST=example.test
HTTP_X_FORWARDED_PROTO=https
--FILE--
<?php
echo $_SERVER['REQUEST_SCHEME'], '://', $_SERVER['HTTP_HOST'], "\n";
echo $_SERVER['HTTPS'], "\n";
echo $_SERVER['SERVER_PORT'], "\n";
echo $_SERVER['SERVER_NAME'], "\n";
--EXPECT--
https://example.test
on
443
example.test
--EXPECT_EXIT--
0
