--TEST--
mb_strlen/mb_convert_encoding/iconv_* excess argc → at most (#30891)
--RUNFILE--
../../../repro/issue_30891_mb_iconv_excess_argc.php
--EXPECT--
mb_strlen:ArgumentCountError:mb_strlen() expects at most 2 arguments, 3 given
mb_convert_encoding:ArgumentCountError:mb_convert_encoding() expects at most 3 arguments, 4 given
iconv_strlen:ArgumentCountError:iconv_strlen() expects at most 2 arguments, 3 given
iconv_substr:ArgumentCountError:iconv_substr() expects at most 4 arguments, 5 given
iconv_strpos:ArgumentCountError:iconv_strpos() expects at most 4 arguments, 5 given
mb_strlen_lo:ArgumentCountError:mb_strlen() expects at least 1 argument, 0 given
mb_convert_encoding_lo:ArgumentCountError:mb_convert_encoding() expects at least 2 arguments, 1 given
iconv_strlen_lo:ArgumentCountError:iconv_strlen() expects at least 1 argument, 0 given
iconv_substr_lo:ArgumentCountError:iconv_substr() expects at least 2 arguments, 1 given
ok_strlen:2
ok_iconv:2
ok_conv:a
