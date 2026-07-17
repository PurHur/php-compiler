--TEST--
stdlib DatePeriod::INCLUDE_END_DATE / EXCLUDE_START_DATE option flags visible to defined()/Reflection (#20071, ext/date/php_date.c)
--FILE--
<?php
echo defined('DatePeriod::INCLUDE_END_DATE') ? '1' : '0', "\n";
echo defined('DatePeriod::EXCLUDE_START_DATE') ? '1' : '0', "\n";
$r = new ReflectionClass(DatePeriod::class);
echo $r->hasConstant('INCLUDE_END_DATE') ? '1' : '0', "\n";
echo $r->hasConstant('EXCLUDE_START_DATE') ? '1' : '0', "\n";
$consts = $r->getConstants();
ksort($consts);
var_export($consts);
echo "\n";
echo DatePeriod::INCLUDE_END_DATE, "\n";
echo DatePeriod::EXCLUDE_START_DATE, "\n";
--EXPECT--
1
1
1
1
array (
  'EXCLUDE_START_DATE' => 1,
  'INCLUDE_END_DATE' => 2,
)
2
1
