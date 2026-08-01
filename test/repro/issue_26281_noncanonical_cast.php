<?php
// Issue #26281 repro — PROFILE=8.5 non-canonical cast deprecation
// Compile-time notices fire during eval (handler already installed), matching Zend lexer timing.
$w = [];
set_error_handler(function ($n, $s) use (&$w) {
    $w[] = "$n:$s";
    return true;
});
eval('$x = (integer)1.5; $y = (boolean)1; $z = (double)1; $b = (binary)"hi"; echo "vals=$x,$y,$z,$b\n";');
var_export($w);
echo "\n";
