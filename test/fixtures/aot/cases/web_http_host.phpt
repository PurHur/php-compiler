--TEST--
AOT: HTTP_HOST and REQUEST_SCHEME from CGI env (issue #235)
--ENV--
REQUEST_METHOD=GET
HTTP_HOST=example.test:8080
HTTPS=on
SCRIPT_NAME=/index.php
REQUEST_URI=/index.php
--FILE--
<?php
echo $_SERVER['HTTP_HOST'], '|', $_SERVER['REQUEST_SCHEME'], '|', $_SERVER['SERVER_PORT'];
--EXPECT--
example.test:8080|https|8080
--EXPECT_EXIT--
0
