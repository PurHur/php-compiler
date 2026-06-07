--TEST--
AOT: strcspn()/strspn() empty $characters — PHP 8.4 full byte length (GH-12592, #7088)
--FILE--
<?php
echo strcspn("a\0b", ""), "\n";
echo strspn("a\0b", ""), "\n";
echo strcspn("a\0b", "", 0, 2), "\n";
echo strspn("a\0b", "", 1, 2), "\n";
--EXPECT--
3
0
2
0
