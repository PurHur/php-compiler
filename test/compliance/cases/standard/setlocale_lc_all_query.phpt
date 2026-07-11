--TEST--
setlocale(LC_ALL, null) query after LC_ALL mutation returns active locale (#17720, ext/standard/locale.c)
--FILE--
<?php
$q1 = setlocale(LC_ALL, null);
$mid = setlocale(LC_ALL, 'C');
$q2 = setlocale(LC_ALL, null);
echo "q1={$q1}\n";
echo "q2={$q2}\n";
--EXPECT--
q1=C
q2=C
