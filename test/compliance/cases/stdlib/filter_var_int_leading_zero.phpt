--TEST--
stdlib filter_var() FILTER_VALIDATE_INT rejects leading-zero strings (issue #3689)
--FILE--
<?php
echo filter_var('0123', FILTER_VALIDATE_INT) === false ? "false\n" : "bad\n";
echo filter_var('123', FILTER_VALIDATE_INT), "\n";
echo filter_var('0', FILTER_VALIDATE_INT), "\n";
echo filter_var('-42', FILTER_VALIDATE_INT), "\n";
echo filter_var('00', FILTER_VALIDATE_INT) === false ? "false\n" : "bad\n";
echo filter_var('-0123', FILTER_VALIDATE_INT) === false ? "false\n" : "bad\n";
--EXPECT--
false
123
0
-42
false
false
