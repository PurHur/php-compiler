--TEST--
AOT: strrchr() via libc strrchr and haystack slice
--FILE--
<?php
echo strrchr("abc-def-ghi", "-"), "\n";
--EXPECT--
-ghi
