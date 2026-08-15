--TEST--
stdlib setcookie() expires attribute (issue #6340, SetcookieLine parity)
--ENV--
GATEWAY_INTERFACE=CGI/1.1
--FILE--
<?php
// Thu, 15-Jan-2026 12:30:45 GMT
setcookie('sid', 'x', 1768480245);
echo headers_list()[0], "\n";
--EXPECT--
Set-Cookie: sid=x; expires=Thu, 15-Jan-2026 12:30:45 GMT
