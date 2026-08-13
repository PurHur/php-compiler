--TEST--
DateInterval::__construct excess argc → ArgumentCountError (#30601) JIT
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
../../../repro/maintainer_gap_dateinterval_ctor_excess_argc_30601.php
--EXPECT--
DateInterval::__construct() expects exactly 1 argument, 2 given
DateInterval::__construct() expects exactly 1 argument, 0 given
OK 1
