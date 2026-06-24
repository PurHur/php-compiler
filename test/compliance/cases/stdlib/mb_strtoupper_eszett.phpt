--TEST--
stdlib mb_strtoupper() maps U+00DF to SS (#11302, ext/mbstring/mbstring.c)
--FILE--
<?php
echo mb_strtoupper('straße', 'UTF-8'), "\n";
echo mb_strtolower('STRASSE', 'UTF-8'), "\n";
echo mb_convert_case('straße', MB_CASE_UPPER, 'UTF-8'), "\n";
--EXPECT--
STRASSE
strasse
STRASSE
