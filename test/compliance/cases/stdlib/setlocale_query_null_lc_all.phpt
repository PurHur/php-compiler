--TEST--
stdlib setlocale(LC_ALL, null) — normalized locale name not composite (#8684, ext/standard/locale.c)
--FILE--
<?php
$q1 = setlocale(LC_ALL, null);
setlocale(LC_ALL, 'C');
$q2 = setlocale(LC_ALL, null);
echo is_string($q1) && !str_contains($q1, ';') ? '1' : '0', "\n";
echo is_string($q2) && !str_contains($q2, ';') ? '1' : '0', "\n";
echo $q1 === $q2 ? '1' : '0', "\n";
--EXPECT--
1
1
1
