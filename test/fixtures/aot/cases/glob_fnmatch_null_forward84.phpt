--TEST--
AOT: glob()/fnmatch() pattern null soft-null on 8.4 (#21366, ext/standard/file.c, fnmatch.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo count(glob(null)), "\n";
echo fnmatch(null, 'a') ? "1\n" : "0\n";
--EXPECT--
0
0
