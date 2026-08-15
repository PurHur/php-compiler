--TEST--
Web: setcookie() records Set-Cookie header for serve
--FILE--
<?php
echo setcookie('k', 'v') ? '1' : '0', "\n";
echo headers_list()[0], "\n";
--EXPECT--
1
Set-Cookie: k=v
