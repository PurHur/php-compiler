--TEST--
AOT: SERVER_SOFTWARE from CGI superglobals refresh
--ENV--
REQUEST_METHOD=GET
SCRIPT_NAME=/index.php
REQUEST_URI=/index.php
--FILE--
<?php
echo $_SERVER['SERVER_SOFTWARE'], "\n";
--EXPECT--
PHP-Compiler-AOT
