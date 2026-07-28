--TEST--
AOT: closure use() and arrow-fn captures (#24106)
--FILE--
<?php
$n = 10;
$f = function () use ($n) { return $n; };
echo $f(), "\n";
$g = function (int $x) use ($n): int { return $x + $n; };
echo $g(5), "\n";
$h = fn(int $x): int => $x + $n;
echo $h(5), "\n";
$i = function (int $x): int { return $x + 1; };
echo $i(5), "\n";
--EXPECT--
10
15
15
6
