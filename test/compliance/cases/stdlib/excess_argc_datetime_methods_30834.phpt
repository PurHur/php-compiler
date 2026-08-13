--TEST--
DateTime/DateTimeZone/DateInterval methods excess argc → ArgumentCountError (#30834)
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
../../../repro/maintainer_gap_datetime_methods_excess_argc_30834.php
--EXPECT--
format: DateTime::format() expects exactly 1 argument, 2 given
modify: DateTime::modify() expects exactly 1 argument, 2 given
setDate: DateTime::setDate() expects exactly 3 arguments, 4 given
setTime5: DateTime::setTime() expects at most 4 arguments, 5 given
getTimestamp: DateTime::getTimestamp() expects exactly 0 arguments, 1 given
add: DateTime::add() expects exactly 1 argument, 2 given
sub: DateTime::sub() expects exactly 1 argument, 2 given
diff: DateTime::diff() expects at most 2 arguments, 3 given
getName: DateTimeZone::getName() expects exactly 0 arguments, 1 given
getOffset: DateTimeZone::getOffset() expects exactly 1 argument, 2 given
getLocation: DateTimeZone::getLocation() expects exactly 0 arguments, 1 given
intervalFormat: DateInterval::format() expects exactly 1 argument, 2 given
ok_format: 2020
