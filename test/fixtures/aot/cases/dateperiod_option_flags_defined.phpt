--TEST--
AOT: DatePeriod::INCLUDE_END_DATE / EXCLUDE_START_DATE defined() (#20071)
--FILE--
<?php
echo defined('DatePeriod::INCLUDE_END_DATE') ? 'ok' : 'bad', "\n";
echo defined('DatePeriod::EXCLUDE_START_DATE') ? 'ok' : 'bad', "\n";
--EXPECT--
ok
ok
