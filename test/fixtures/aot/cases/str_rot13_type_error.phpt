--TEST--
AOT: str_rot13() — TypeError for non-string operand (#4578)
--FILE--
<?php
str_rot13([]);
--EXPECT--
--EXPECT_EXIT--
134
