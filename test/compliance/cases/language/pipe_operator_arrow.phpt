--TEST--
PHP 8.5 pipe operator with parenthesized arrow-function RHS and chained pipes (issue #6705, #7219, #7244)
--FILE--
<?php
$x = 5 |> (fn($v) => $v * 2);
var_export($x);
echo "\n";
echo 3 |> (fn($x) => $x + 1) |> (fn($x) => $x * 2), "\n";
echo 5 |> (fn($x) => $x * 2)(), "\n";
echo 5 |> (fn(int $x): int => $x * 2)(), "\n";
--EXPECT--
10
8
10
10
