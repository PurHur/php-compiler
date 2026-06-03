--TEST--
stdlib strlen() — null deprecated, returns 0 (PHP 8.2+, #5000, ext/standard/string.c)
--FILE--
<?php
echo strlen(null), "\n";
echo strlen(''), "\n";
--EXPECT--
0
0
