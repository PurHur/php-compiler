--TEST--
stdlib filter_var() options array flags FILTER_NULL_ON_FAILURE (#17437, re-#12326)
--FILE--
<?php
declare(strict_types=1);
echo filter_var('not-int', FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]) === null ? "null\n" : "bad\n";
echo filter_var('bad@', FILTER_VALIDATE_EMAIL, ['flags' => FILTER_NULL_ON_FAILURE]) === null ? "null\n" : "bad\n";
echo filter_var('42', FILTER_VALIDATE_INT, ['flags' => FILTER_NULL_ON_FAILURE]), "\n";
--EXPECT--
null
null
42
