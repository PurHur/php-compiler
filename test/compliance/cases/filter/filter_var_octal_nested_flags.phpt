--TEST--
filter_var() ignores FILTER_FLAG_ALLOW_OCTAL nested under options[] (#22772)
--FILE--
<?php
declare(strict_types=1);
echo filter_var('01', FILTER_VALIDATE_INT, ['options' => ['flags' => FILTER_FLAG_ALLOW_OCTAL]]) === false ? "nested_false\n" : "nested_bad\n";
echo filter_var('01', FILTER_VALIDATE_INT, ['flags' => FILTER_FLAG_ALLOW_OCTAL]), "\n";
echo filter_var('01', FILTER_VALIDATE_INT) === false ? "none_false\n" : "none_bad\n";
echo filter_var('01', FILTER_VALIDATE_INT, ['flags' => FILTER_FLAG_ALLOW_HEX, 'options' => ['flags' => FILTER_FLAG_ALLOW_OCTAL]]) === false ? "both_false\n" : "both_bad\n";
--EXPECT--
nested_false
1
none_false
both_false
