--TEST--
stdlib setrawcookie() VM (raw value, no URL encoding)
--FILE--
<?php
echo setrawcookie('tok', 'a=b') ? 'ok' : 'no', "\n";
echo header_list()[0], "\n";
--EXPECT--
ok
Set-Cookie: tok=a=b
