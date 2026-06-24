--TEST--
stdlib mb_strtoupper()/mb_strtolower()/mb_convert_case() UTF-8 non-ASCII (#11146, #11129, ext/mbstring/mbstring.c)
--FILE--
<?php
echo mb_strtoupper('über', 'UTF-8'), "\n";
echo mb_strtolower('ÜBER', 'UTF-8'), "\n";
echo mb_convert_case('über', MB_CASE_TITLE, 'UTF-8'), "\n";
echo mb_strtoupper('α', 'UTF-8'), "\n";
--EXPECT--
ÜBER
über
Über
Α
