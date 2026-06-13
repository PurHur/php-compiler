--TEST--
stdlib sort() with SORT_LOCALE_STRING uses locale collation (#4745)
--FILE--
<?php
setlocale(LC_COLLATE, 'de_DE.UTF-8');
$a = ['z', 'ä', 'b'];
sort($a, SORT_LOCALE_STRING);
echo implode(',', $a), "\n";
--EXPECT--
b,z,ä
