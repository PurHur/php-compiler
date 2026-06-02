--TEST--
AOT: crc32() — TypeError for non-string operand (#4594)
--FILE--
<?php
crc32([]);
--EXPECT--
--EXPECT_EXIT--
134
