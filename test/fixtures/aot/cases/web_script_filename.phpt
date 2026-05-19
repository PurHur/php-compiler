--TEST--
AOT: SCRIPT_FILENAME from CGI env (issue #302)
--ENV--
REQUEST_METHOD=GET
SCRIPT_NAME=/index.php
REQUEST_URI=/index.php
DOCUMENT_ROOT=/var/www/html
SCRIPT_FILENAME=/var/www/html/index.php
--FILE--
<?php
echo $_SERVER['SCRIPT_FILENAME'];
--EXPECT--
/var/www/html/index.php
--EXPECT_EXIT--
0
