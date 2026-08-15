--TEST--
AOT: setcookie() with headers_list() pending queue (issue #1170 / #28404)
--FILE--
<?php
setcookie('sid', 'x', 0, '/');
echo count(headers_list()), "\n";
echo headers_list()[0], "\n";
echo "ok\n";
--EXPECT--
1
Set-Cookie: sid=x; path=/
ok
