--TEST--
stdlib strcspn() empty $characters + NUL — PROFILE=8.4 full length (#27716, GH-12592)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
echo strcspn("ab\0c", ""), "\n";
echo strcspn("abc", ""), "\n";
--EXPECT--
4
3
