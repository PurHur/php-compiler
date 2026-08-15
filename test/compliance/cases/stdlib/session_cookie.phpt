--TEST--
Stdlib: session cookie header on session_start() VM (#64)
--ENV--
GATEWAY_INTERFACE=CGI/1.1
--FILE--
<?php
session_start();
$headers = headers_list();
echo substr($headers[0], 0, 22) === 'Set-Cookie: PHPSESSID=' ? 'cookie' : 'nocookie', "\n";
--EXPECT--
cookie
