--TEST--
stdlib setcookie() options array — SameSite, Secure, HttpOnly (#3507, ext/standard/head.c)
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
echo setcookie('ok', '1', ['path' => '/app']) ? "ok\n" : "no\n";
echo headers_list()[0], "\n";
echo headers_list()[1], "\n";
--EXPECT--
ok
Set-Cookie: n=v; expires=Thu, 15-Jan-2026 12:30:45 GMT; path=/; secure; httponly; samesite=Lax
Set-Cookie: ok=1; path=/app
