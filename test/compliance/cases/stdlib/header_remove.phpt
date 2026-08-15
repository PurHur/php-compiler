--TEST--
stdlib header_remove() and headers_list() (issue #311)
--ENV--
GATEWAY_INTERFACE=CGI/1.1
--FILE--
<?php
header('X-Test: 1');
header_remove('X-Test');
echo count(headers_list()), "\n";
header('Content-Type: application/json');
header_remove('Content-Type');
header('Content-Type: text/plain');
echo count(headers_list()), "\n";
echo headers_list()[0], "\n";
header_remove();
echo count(headers_list()), "\n";
--EXPECT--
0
1
Content-Type: text/plain
0
