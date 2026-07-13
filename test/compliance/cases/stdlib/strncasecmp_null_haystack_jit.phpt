--TEST--
stdlib strncasecmp() null haystack — JIT coerce to empty string (#18700)
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
