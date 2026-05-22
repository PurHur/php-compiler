--TEST--
AOT: filter_input() INPUT_GET email and int (issue #104)
--GET--
email=user@example.com&count=7
--FILE--
<?php
echo filter_input(INPUT_GET, 'email', FILTER_VALIDATE_EMAIL), "\n";
echo filter_input(INPUT_GET, 'count', FILTER_VALIDATE_INT), "\n";
echo filter_input(INPUT_GET, 'missing', FILTER_VALIDATE_INT) === null ? "null\n" : "bad\n";
--EXPECT--
user@example.com
7
null
--EXPECT_EXIT--
0
