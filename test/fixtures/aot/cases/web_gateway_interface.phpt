--TEST--
AOT: GATEWAY_INTERFACE from CGI superglobals refresh
--ENV--
REQUEST_METHOD=GET
SCRIPT_NAME=/index.php
REQUEST_URI=/index.php
--FILE--
<?php
echo $_SERVER['GATEWAY_INTERFACE'], "\n";
--EXPECT--
CGI/1.1
