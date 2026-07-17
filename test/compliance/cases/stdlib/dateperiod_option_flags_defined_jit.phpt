--TEST--
stdlib DatePeriod option flags defined() + :: fetch JIT (#20071, ext/date/php_date.c)
--FILE--
<?php
echo defined('DatePeriod::INCLUDE_END_DATE') ? '1' : '0', "\n";
echo defined('DatePeriod::EXCLUDE_START_DATE') ? '1' : '0', "\n";
echo DatePeriod::INCLUDE_END_DATE, "\n";
echo DatePeriod::EXCLUDE_START_DATE, "\n";
--EXPECT--
1
1
2
1
