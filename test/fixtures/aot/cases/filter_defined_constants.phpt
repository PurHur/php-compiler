--TEST--
AOT: FILTER_* constants in filter bucket (#13046)
--FILE--
<?php
$filter = get_defined_constants(true)['filter'] ?? [];
echo defined('FILTER_DEFAULT') && FILTER_DEFAULT === 516 ? "1" : "0";
echo defined('FILTER_FLAG_NONE') && FILTER_FLAG_NONE === 0 ? "1" : "0";
echo isset($filter['FILTER_VALIDATE_INT']) && $filter['FILTER_VALIDATE_INT'] === 257 ? "1" : "0";
--EXPECT--
111
