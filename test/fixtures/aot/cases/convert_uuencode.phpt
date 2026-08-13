--TEST--
AOT: convert_uuencode() / convert_uudecode() round-trip (#26898, #30811)
--FILE--
<?php
echo substr(convert_uuencode("test"), 0, 12), "\n";
echo convert_uudecode(convert_uuencode("test")), "\n";
echo convert_uudecode(convert_uuencode("cat")), "\n";
--EXPECT--
$=&5S=```
`

test
cat
--EXPECT_EXIT--
0
