--TEST--
stdlib nl2br() CRLF and lone CR (php-src string.c parity)
--FILE--
<?php
echo nl2br("a\r\nb"), "\n";
echo bin2hex(nl2br("a\rb")), "\n";
echo bin2hex(nl2br("a\n\rb")), "\n";
--EXPECT--
a<br />
b
613c6272202f3e0d62
613c6272202f3e0a0d62
