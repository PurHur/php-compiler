--TEST--
AOT: read cookies from $_COOKIE via HTTP_COOKIE env
--ENV--
HTTP_COOKIE=session=abc123
--FILE--
<?php
echo 'session=', $_COOKIE['session'], "\n";
--EXPECT--
session=abc123
--EXPECT_EXIT--
0
