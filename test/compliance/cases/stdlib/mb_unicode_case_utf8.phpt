--TEST--
stdlib mb_strtoupper()/mb_strtolower()/mb_convert_case() UTF-8 non-ASCII (#11146, #11129, #25964, ext/mbstring/mbstring.c)
--FILE--
<?php
echo mb_strtoupper('über', 'UTF-8'), "\n";
echo mb_strtolower('ÜBER', 'UTF-8'), "\n";
echo mb_convert_case('über', MB_CASE_TITLE, 'UTF-8'), "\n";
echo mb_strtoupper('α', 'UTF-8'), "\n";
echo bin2hex(mb_strtolower('İ', 'UTF-8')), "\n";
echo mb_strtolower('IŞIK', 'UTF-8'), "\n";
echo mb_strtoupper('ışık', 'UTF-8'), "\n";
echo mb_convert_case('iŞık', MB_CASE_TITLE, 'UTF-8'), "\n";
echo mb_convert_case('ĳssel', MB_CASE_TITLE, 'UTF-8'), "\n";
echo mb_convert_case('Ǆabc', MB_CASE_TITLE, 'UTF-8'), "\n";
echo mb_convert_case('ş', MB_CASE_TITLE, 'UTF-8'), "\n";
--EXPECT--
ÜBER
über
Über
Α
69cc87
işik
IŞIK
Işık
Ĳssel
ǅabc
Ş
