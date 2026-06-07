--TEST--
AOT: str_shuffle() — TypeError for non-string operand (#4551)
--FILE--
<?php
str_shuffle([]);
--EXPECT--
--EXPECT_EXIT--
134
