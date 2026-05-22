--TEST--
stdlib filter_var() FILTER_VALIDATE_INT and FILTER_VALIDATE_EMAIL
--FILE--
<?php
echo filter_var('42', FILTER_VALIDATE_INT), "\n";
echo filter_var('12abc', FILTER_VALIDATE_INT) === false ? "false\n" : "bad\n";
echo filter_var('user@example.com', FILTER_VALIDATE_EMAIL), "\n";
echo filter_var('not-an-email', FILTER_VALIDATE_EMAIL) === false ? "false\n" : "bad\n";
echo filter_var(null, FILTER_VALIDATE_INT) === false ? "false\n" : "bad\n";
--EXPECT--
42
false
user@example.com
false
false
