--TEST--
AOT: strcasecmp() and strncasecmp() via libc
--FILE--
<?php
echo strcasecmp('abc', 'ABC'), "\n";
echo strncasecmp('abc', 'ABD', 2), "\n";
echo strncasecmp('abc', 'ABC', 3), "\n";
--EXPECT--
0
0
0
