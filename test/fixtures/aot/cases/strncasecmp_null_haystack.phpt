--TEST--
AOT: strncasecmp() null haystack coerce (#18700)
--FILE--
<?php
echo strncasecmp(null, 'a', 1), "\n";
echo strncasecmp('', 'a', 1), "\n";
echo strncasecmp('a', null, 1), "\n";
echo strncasecmp('ab', 'ABC', 3), "\n";
--EXPECT--
-1
-1
1
-1
