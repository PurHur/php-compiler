--TEST--
AOT: setcookie() domain/secure/httponly (issue #1170)
--FILE--
<?php
setcookie('k', 'v', 0, '/', 'localhost', true, true);
echo headers_list()[0], "\n";
--EXPECT--
Set-Cookie: k=v; path=/; domain=localhost; secure; httponly
Set-Cookie: k=v; path=/; domain=localhost; secure; httponly
