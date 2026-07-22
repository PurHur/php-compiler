--TEST--
stdlib filter_var() FILTER_VALIDATE_INT trims whitespace (issue #21962, logical_filters.c php_filter_int)
--FILE--
<?php
echo filter_var(' 42 ', FILTER_VALIDATE_INT), "\n";
echo filter_var('42 ', FILTER_VALIDATE_INT), "\n";
echo filter_var("\t42\t", FILTER_VALIDATE_INT), "\n";
echo filter_var('42', FILTER_VALIDATE_INT), "\n";
echo filter_var(' 0 ', FILTER_VALIDATE_INT), "\n";
echo filter_var(' 0123 ', FILTER_VALIDATE_INT) === false ? "false\n" : "bad\n";
echo filter_var('   ', FILTER_VALIDATE_INT) === false ? "false\n" : "bad\n";
--EXPECT--
42
42
42
42
0
false
false
