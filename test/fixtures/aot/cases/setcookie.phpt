--TEST--
AOT: setcookie() emits Set-Cookie CGI header line
--FILE--
<?php
setcookie('sid', 'x');
echo "ok\n";
--EXPECT--
Set-Cookie: sid=x
ok
