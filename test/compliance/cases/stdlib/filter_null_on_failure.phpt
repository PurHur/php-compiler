--TEST--
stdlib filter_var() FILTER_NULL_ON_FAILURE returns null on failed validation (issue #3805)
--FILE--
<?php
echo defined('FILTER_NULL_ON_FAILURE') ? '1' : '0';
echo "\n";
echo filter_var('not-int', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE) === null ? "null\n" : "bad\n";
echo filter_var('not-int', FILTER_VALIDATE_INT) === false ? "false\n" : "bad\n";
echo filter_var('42', FILTER_VALIDATE_INT, FILTER_NULL_ON_FAILURE), "\n";
echo filter_var('bad@', FILTER_VALIDATE_EMAIL, FILTER_NULL_ON_FAILURE) === null ? "null\n" : "bad\n";
--EXPECT--
1
null
false
42
null
