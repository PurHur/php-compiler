--TEST--
setlocale(LC_ALL, null) query after LC_ALL mutation returns active locale (#17720, #18210, ext/standard/locale.c)
--FILE--
<?php
$q1 = setlocale(LC_ALL, null);
$mid = setlocale(LC_ALL, 'C');
$q2 = setlocale(LC_ALL, null);
echo ($q1 === $q2) ? "same\n" : "diff\n";
echo is_string($mid) && !str_contains($mid, ';') ? "mid_ok\n" : "mid_bad\n";
--EXPECT--
same
mid_ok
