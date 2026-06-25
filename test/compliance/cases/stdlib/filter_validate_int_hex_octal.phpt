--TEST--
stdlib filter_var() FILTER_VALIDATE_INT ALLOW_HEX/ALLOW_OCTAL (issue #11757, logical_filters.c)
--FILE--
<?php
echo filter_var('0x10', FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_HEX), "\n";
echo filter_var('010', FILTER_VALIDATE_INT, FILTER_FLAG_ALLOW_OCTAL), "\n";
echo filter_var('0x10', FILTER_VALIDATE_INT) === false ? "false\n" : "bad\n";
--EXPECT--
16
8
false
