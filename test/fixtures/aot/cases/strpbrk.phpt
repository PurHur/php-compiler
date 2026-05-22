--TEST--
AOT: strpbrk() via libc strpbrk and haystack slice
--FILE--
<?php
echo strpbrk("abc-def-ghi", "-"), "\n";
--EXPECT--
-def-ghi
