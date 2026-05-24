--TEST--
AOT: setcookie() with header_list() pending queue (issue #1170)
--FILE--
<?php
setcookie('sid', 'x', 0, '/');
echo count(header_list()), "\n";
echo header_list()[0], "\n";
echo "ok\n";
--EXPECT--
Set-Cookie: sid=x; path=/
1
Set-Cookie: sid=x; path=/
ok
