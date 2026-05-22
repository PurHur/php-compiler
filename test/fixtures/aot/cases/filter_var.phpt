--TEST--
AOT: filter_var() int and email validation (issue #104)
--FILE--
<?php
echo filter_var('99', FILTER_VALIDATE_INT), "\n";
echo filter_var('bad', FILTER_VALIDATE_INT) === false ? "false\n" : "x\n";
echo filter_var('a@b.co', FILTER_VALIDATE_EMAIL), "\n";
--EXPECT--
99
false
a@b.co
--EXPECT_EXIT--
0
