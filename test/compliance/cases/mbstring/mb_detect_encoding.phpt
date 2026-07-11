--TEST--
stdlib mb_detect_encoding() — ISO-8859-1 vs UTF-8 strict probe (#3075, ext/mbstring/mbstring.c)
--FILE--
<?php
$iso = "\xE9";
echo mb_detect_encoding($iso, ['ISO-8859-1', 'UTF-8'], true), "\n";
echo mb_detect_encoding('ascii', ['ASCII', 'UTF-8'], true), "\n";
echo mb_detect_encoding('abc', 'UTF-8,ASCII', true), "\n";
echo mb_detect_encoding('abc', 'ASCII,UTF-8', true), "\n";
echo mb_detect_encoding("\xC3\xA9", ['ISO-8859-1', 'UTF-8'], true), "\n";
echo mb_detect_encoding($iso, 'ISO-8859-1,UTF-8', true), "\n";
var_export(mb_detect_encoding("\xFF", ['UTF-8'], true));
echo "\n";
--EXPECT--
ISO-8859-1
ASCII
UTF-8
ASCII
UTF-8
ISO-8859-1
false
