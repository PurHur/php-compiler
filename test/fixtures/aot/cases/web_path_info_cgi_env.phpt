--TEST--
AOT: PATH_INFO from CGI PATH_INFO env var
--ENV--
SCRIPT_NAME=/index.php
REQUEST_URI=/index.php/admin
REQUEST_METHOD=GET
PATH_INFO=/from-cgi
--FILE--
<?php
echo $_SERVER['PATH_INFO'];
--EXPECT--
/from-cgi
--EXPECT_EXIT--
0
