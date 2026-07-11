--TEST--
PHP 8.4 pipe operator with unparenthesized arrow-function RHS (issue #11858, #7219)
--FILE--
<?php
$x = 5 |> fn($v) => $v * 2;
var_export($x);
echo "\n";
echo 3 |> fn($x) => $x + 1 |> fn($x) => $x * 2, "\n";
echo 5 |> fn($x) => $x * 2(), "\n";
--EXPECT--
10
8
10
