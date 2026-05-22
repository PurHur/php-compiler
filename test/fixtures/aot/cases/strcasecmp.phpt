--TEST--
AOT: strcasecmp() and strncasecmp() via libc
--FILE--
<?php
echo strcasecmp('Content-Type', 'content-type'), "\n";
echo strncasecmp('HTTP/', 'http/', 5), "\n";
--EXPECT--
0
0
