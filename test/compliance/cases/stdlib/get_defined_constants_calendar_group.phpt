--TEST--
get_defined_constants(true) calendar extension group (#17416, Zend/zend_builtin_functions.c)
--FILE--
<?php
$c = get_defined_constants(true);
echo defined('CAL_GREGORIAN') && CAL_GREGORIAN === 0 ? "defined_ok\n" : "defined_bad\n";
echo isset($c['calendar']['CAL_GREGORIAN']) && $c['calendar']['CAL_GREGORIAN'] === 0 ? "bucket_ok\n" : "bucket_bad\n";
--EXPECT--
defined_ok
bucket_ok
