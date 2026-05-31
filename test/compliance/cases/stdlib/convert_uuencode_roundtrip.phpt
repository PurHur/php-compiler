--TEST--
stdlib convert_uuencode() / convert_uudecode() round-trip
--FILE--
<?php
$raw = "Hello\n";
$enc = convert_uuencode($raw);
echo $enc;
echo convert_uudecode($enc);
echo "---\n";
$big = str_repeat('A', 50);
echo (convert_uudecode(convert_uuencode($big)) === $big) ? "big-ok\n" : "big-fail\n";
echo bin2hex(convert_uuencode('')), "\n";
--EXPECT--
&2&5L;&\*
`
Hello
---
big-ok
600a
--EXPECT_EXIT--
0
