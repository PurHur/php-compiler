--TEST--
stdlib headers_sent() — enum case by-ref operands reject non-referenceable expressions (#7412, head.c)
--RUNFILE--
headers_sent_enum.inc.php
--EXPECT--
file literal: Error
headers_sent(): Argument #1 ($filename) could not be passed by reference
line literal: Error
headers_sent(): Argument #2 ($line) could not be passed by reference
not-sent
string
0
0
--CREDITS--
PurHur/php-compiler issue #7412
