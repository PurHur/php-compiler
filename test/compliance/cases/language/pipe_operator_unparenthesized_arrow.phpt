--TEST--
PHP 8.5 pipe operator rejects unparenthesized arrow function RHS (issue #7219)
--FILE--
<?php
echo 1 |> fn($x) => $x;
--EXPECT_EXIT--
255
--EXPECTREGEX--
must be parenthesized
