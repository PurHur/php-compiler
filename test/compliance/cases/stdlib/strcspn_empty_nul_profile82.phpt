--TEST--
stdlib strcspn() empty $characters + NUL — PROFILE=8.2 stops at NUL (#27716, GH-12592 inverse)
--ENV--
PHP_COMPILER_PROFILE=8.2
--FILE--
<?php
echo strcspn("ab\0c", ""), "\n";
echo strcspn("abc", ""), "\n";
echo strcspn("ab\0c", "", 1), "\n";
--EXPECT--
2
3
1
