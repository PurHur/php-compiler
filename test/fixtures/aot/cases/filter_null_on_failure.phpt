--TEST--
AOT filter_var() FILTER_NULL_ON_FAILURE (issue #3805)
--FILE--
<?php
echo defined('FILTER_NULL_ON_FAILURE') ? "1\n" : "0\n";
echo filter_var('bad', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) === null ? "null\n" : "x\n";
echo filter_var('bad', FILTER_VALIDATE_INT) === false ? "false\n" : "x\n";
echo filter_var('7', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE), "\n";
--EXPECT--
1
null
false
7
