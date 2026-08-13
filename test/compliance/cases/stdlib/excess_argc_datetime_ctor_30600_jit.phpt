--TEST--
DateTime/DateTimeImmutable::__construct excess argc → ArgumentCountError (#30600) JIT
--ENV--
PHP_COMPILER_PROFILE=8.4
--RUNFILE--
../../../repro/maintainer_gap_datetime_ctor_excess_argc_30600.php
--EXPECT--
DateTime DateTime::__construct() expects at most 2 arguments, 3 given
DateTimeImmutable DateTimeImmutable::__construct() expects at most 2 arguments, 3 given
DT_OK yes
DTI_OK 2020-01-01
