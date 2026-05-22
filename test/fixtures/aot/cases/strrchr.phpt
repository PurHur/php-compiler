--TEST--
AOT: strrchr() via libc strrchr and haystack slice
--FILE--
<?php
echo strrchr('path/to/file.php', '/'), "\n";
echo strrchr('a/b/c', 'b'), "\n";
--EXPECT--
/file.php
/b/c
