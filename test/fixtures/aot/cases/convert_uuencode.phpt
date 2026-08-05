--TEST--
AOT: convert_uuencode() / convert_uudecode() round-trip (#26898)
--FILE--
<?php
echo convert_uudecode(convert_uuencode("cat")), "\n";
--EXPECT--
cat
--EXPECT_EXIT--
0
