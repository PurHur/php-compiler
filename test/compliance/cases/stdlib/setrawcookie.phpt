--TEST--
stdlib setrawcookie() VM (raw value, no URL encoding)
--ENV--
GATEWAY_INTERFACE=CGI/1.1
--FILE--
<?php
echo setrawcookie('tok', 'a=b') ? 'ok' : 'no', "\n";
echo headers_list()[0], "\n";
--EXPECT--
ok
Set-Cookie: tok=a=b
