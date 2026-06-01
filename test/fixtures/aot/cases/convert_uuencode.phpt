--TEST--
AOT: convert_uuencode() / convert_uudecode()
--FILE--
<?php
$raw = "Hello\n";
$enc = convert_uuencode($raw);
echo convert_uudecode($enc);
echo (convert_uudecode('bad') === false) ? "\nfalse" : "\nnot-false";
--EXPECT--
Hello
false
--EXPECT_EXIT--
0
