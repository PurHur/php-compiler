--TEST--
stdlib iconv() ASCII//TRANSLIT transliterates UTF-8 to ASCII (#10568, #32103, ext/iconv/iconv.c)
--FILE--
<?php
$r = iconv('UTF-8', 'ASCII//TRANSLIT', 'café');
var_export($r);
echo "\n";
$r2 = iconv('UTF-8', 'ASCII//IGNORE', "caf\xC3\xA9");
var_export($r2);
echo "\n";
$r3 = iconv('UTF-8', 'ASCII//TRANSLIT', 'a€b');
var_export($r3);
echo "\n";
$r4 = iconv('UTF-8', 'ASCII//IGNORE', 'a€b');
var_export($r4);
--EXPECT--
'cafe'
'caf'
'aEURb'
'ab'
