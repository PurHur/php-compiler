--TEST--
AOT: $_SERVER CGI fields from refreshed environ (real/web_server_get)
--ENV--
QUERY_STRING=name=World
REQUEST_METHOD=GET
SCRIPT_NAME=/app/index.php
REQUEST_URI=/app/index.php?name=World
--FILE--
<?php
echo $_SERVER['REQUEST_METHOD'], "\n";
echo $_SERVER['QUERY_STRING'], "\n";
echo $_SERVER['SCRIPT_NAME'], "\n";
echo $_SERVER['REQUEST_URI'], "\n";
--EXPECT--
GET
name=World
/app/index.php
/app/index.php?name=World
--EXPECT_EXIT--
0
