--TEST--
AOT: mb_strtoupper()/mb_strtolower()/mb_convert_case() UTF-8 non-ASCII (#11146, #25964)
--FILE--
<?php
echo mb_strtoupper('über', 'UTF-8'), "\n";
echo mb_convert_case('über', MB_CASE_TITLE, 'UTF-8'), "\n";
echo mb_strtolower('IŞIK', 'UTF-8'), "\n";
echo mb_convert_case('Ǆabc', MB_CASE_TITLE, 'UTF-8'), "\n";
--EXPECT--
ÜBER
Über
işik
ǅabc
