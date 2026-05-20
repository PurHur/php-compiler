--TEST--
stdlib setcookie() VM (ResponseContext / header line)
--FILE--
<?php
echo setcookie('sid', 'abc') ? 'ok' : 'no', "\n";
echo header_list()[0], "\n";
--EXPECT--
ok
Set-Cookie: sid=abc
