--TEST--
AOT: header() emits CGI-style response line
--FILE--
<?php
header('Content-Type: text/plain');
echo header_list()[0], "\n";
echo "ok\n";
--EXPECT--
Content-Type: text/plain
ok
