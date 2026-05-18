--TEST--
Web: $_SERVER HTTP_* from CGI environment (issue #193)
--ENV--
REQUEST_METHOD=GET
SCRIPT_NAME=/app/index.php
REQUEST_URI=/app?name=test
HTTP_HOST=example.test
HTTP_X_CUSTOM=1
--FILE--
<?php
echo $_SERVER['HTTP_HOST'], $_SERVER['HTTP_X_CUSTOM'], "\n";
--EXPECT--
example.test1
