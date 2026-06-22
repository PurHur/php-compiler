--TEST--
stdlib iconv() ASCII//TRANSLIT transliterates UTF-8 to ASCII (#10568, ext/iconv/iconv.c)
--FILE--
<?php
$r = iconv('UTF-8', 'ASCII//TRANSLIT', 'café');
var_export($r);
echo "\n";
$r2 = iconv('UTF-8', 'ASCII//IGNORE', "caf\xC3\xA9");
var_export($r2);
--EXPECT--
'cafe'
'caf'
