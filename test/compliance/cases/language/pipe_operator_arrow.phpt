--TEST--
PHP 8.4 pipe operator with arrow-function RHS and chained pipes (issue #6705)
--FILE--
<?php
$x = 5 |> fn($v) => $v * 2;
var_export($x);
echo "\n";
echo 3 |> fn($x) => $x + 1 |> fn($x) => $x * 2, "\n";
--EXPECT--
10
8
