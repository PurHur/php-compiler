--TEST--
Web: PATH_INFO from CGI PATH_INFO env var
--ENV--
SCRIPT_NAME=/index.php
REQUEST_URI=/index.php/other
PATH_INFO=/from-cgi
REQUEST_METHOD=GET
--FILE--
<?php
echo isset($_SERVER['PATH_INFO']) ? $_SERVER['PATH_INFO'] : '';
--EXPECT--
/from-cgi
