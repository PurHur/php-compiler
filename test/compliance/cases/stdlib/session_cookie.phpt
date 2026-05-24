--TEST--
Stdlib: session cookie header on session_start() VM (#64)
--FILE--
<?php
session_start();
$headers = header_list();
echo substr($headers[0], 0, 22) === 'Set-Cookie: PHPSESSID=' ? 'cookie' : 'nocookie', "\n";
--EXPECT--
cookie
