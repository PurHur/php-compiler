--TEST--
mb_str_split/mb_convert_case/mb_scrub/mb_substr_count excess argc → at most (#30786)
--RUNFILE--
../../../repro/issue_30786_mb_excess_argc.php
--EXPECT--
mb_str_split:ArgumentCountError:mb_str_split() expects at most 3 arguments, 4 given
mb_convert_case:ArgumentCountError:mb_convert_case() expects at most 3 arguments, 4 given
mb_scrub:ArgumentCountError:mb_scrub() expects at most 2 arguments, 3 given
mb_substr_count:ArgumentCountError:mb_substr_count() expects at most 3 arguments, 4 given
mb_str_split_lo:ArgumentCountError:mb_str_split() expects at least 1 argument, 0 given
ok_split:a,b
ok_case:A
ok_count:2
