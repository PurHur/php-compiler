--TEST--
stdlib filter_var() FILTER_NULL_ON_FAILURE via flags options array (issue #17437)
--FILE--
<?php
echo filter_var('not-int', FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) === null ? "null\n" : "bad\n";
echo filter_var('bad@', FILTER_VALIDATE_EMAIL, ['flags' => FILTER_NULL_ON_FAILURE]) === null ? "null\n" : "bad\n";
echo filter_var('42', FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]), "\n";
--EXPECT--
null
null
42
