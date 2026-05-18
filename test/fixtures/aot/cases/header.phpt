--TEST--
AOT: header() emits CGI-style response line
--FILE--
<?php
header('Content-Type: text/plain');
echo "ok\n";
--EXPECT--
Content-Type: text/plain
ok
