--TEST--
AOT: mb_strtoupper()/mb_convert_case() UTF-8 non-ASCII (#11146)
--FILE--
<?php
echo mb_strtoupper('über', 'UTF-8'), "\n";
echo mb_convert_case('über', MB_CASE_TITLE, 'UTF-8'), "\n";
--EXPECT--
ÜBER
Über
