--TEST--
stdlib filter_var() FILTER_VALIDATE_INT rejects overflow past PHP_INT_MAX (issue #17585)
--FILE--
<?php
$overflow = PHP_INT_MAX . '0';
echo filter_var($overflow, FILTER_VALIDATE_INT) === false ? "false\n" : "bad\n";
echo filter_var('0123', FILTER_VALIDATE_INT) === false ? "false\n" : "bad\n";
echo filter_var((string) PHP_INT_MAX, FILTER_VALIDATE_INT), "\n";
echo filter_var((string) PHP_INT_MIN, FILTER_VALIDATE_INT), "\n";
--EXPECT--
false
false
9223372036854775807
-9223372036854775808
