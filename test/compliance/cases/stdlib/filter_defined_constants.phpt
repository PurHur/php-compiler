--TEST--
stdlib FILTER_* constants — defined() + get_defined_constants(true)['filter'] (#13046, ext/filter/filter.c)
--FILE--
<?php
$filter = get_defined_constants(true)['filter'] ?? [];
echo defined('FILTER_DEFAULT') && FILTER_DEFAULT === 516 ? "default_ok\n" : "default_bad\n";
echo defined('FILTER_FLAG_NONE') && FILTER_FLAG_NONE === 0 ? "flag_none_ok\n" : "flag_none_bad\n";
echo defined('FILTER_VALIDATE_INT') && FILTER_VALIDATE_INT === 257 ? "validate_int_ok\n" : "validate_int_bad\n";
echo isset($filter['FILTER_DEFAULT']) && $filter['FILTER_DEFAULT'] === 516 ? "bucket_default_ok\n" : "bucket_default_bad\n";
echo isset($filter['FILTER_VALIDATE_INT']) && $filter['FILTER_VALIDATE_INT'] === 257 ? "bucket_validate_int_ok\n" : "bucket_validate_int_bad\n";
--EXPECT--
default_ok
flag_none_ok
validate_int_ok
bucket_default_ok
bucket_validate_int_ok
