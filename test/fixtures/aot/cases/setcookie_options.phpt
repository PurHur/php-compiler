--TEST--
AOT: setcookie() options array (#3507)
--ENV--
GATEWAY_INTERFACE=CGI/1.1
--FILE--
<?php
setcookie('n', 'v', [
    'expires' => 1768480245,
    'path' => '/',
    'secure' => true,
    'httponly' => true,
    'samesite' => 'Lax',
]);
echo headers_list()[0], "\n";
--EXPECT--
Set-Cookie: n=v; expires=Thu, 15-Jan-2026 12:30:45 GMT; path=/; secure; httponly; samesite=Lax
Set-Cookie: n=v; expires=Thu, 15-Jan-2026 12:30:45 GMT; path=/; secure; httponly; samesite=Lax
