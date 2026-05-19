--TEST--
AOT: DOCUMENT_ROOT from CGI env (issue #296)
--ENV--
REQUEST_METHOD=GET
SCRIPT_NAME=/index.php
REQUEST_URI=/index.php
DOCUMENT_ROOT=/var/www/html
--FILE--
<?php
echo $_SERVER['DOCUMENT_ROOT'];
--EXPECT--
/var/www/html
--EXPECT_EXIT--
0
