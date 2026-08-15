--TEST--
stdlib setcookie() VM (ResponseContext / header line)
--ENV--
GATEWAY_INTERFACE=CGI/1.1
--FILE--
<?php
echo setcookie('sid', 'abc') ? 'ok' : 'no', "\n";
echo headers_list()[0], "\n";
--EXPECT--
ok
Set-Cookie: sid=abc
