--TEST--
stdlib headers_list() JIT with CGI header queue (issue #4110)
--JIT--
--ENV--
GATEWAY_INTERFACE=CGI/1.1
--FILE--
<?php
header('X-Test: 1');
header('X-Test: 2', false);
echo count(headers_list()), "\n";
echo headers_list()[0], "\n";
--EXPECT--
2
X-Test: 1
