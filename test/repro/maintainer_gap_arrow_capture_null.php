<?php
/** Maintainer repro for #10304 — arrow function outer variable capture (#4944, #4952). */
declare(strict_types=1);

$x = 1;
$af = fn () => $x;
var_export($af());
echo "\n";

$msg = 'hello';
$g = fn () => $msg;
echo $g(), "\n";

$f = fn (int $n) => fn () => $n * 2;
echo $f(3)(), "\n";
