<?php
// Issue #26279 repro — PROFILE=8.5 case semicolon deprecation
$w = [];
set_error_handler(function ($n, $s) use (&$w) {
    $w[] = "$n:$s";
    return true;
});
eval('$x=1; switch ($x) { case 1; echo "hit\n"; break; }');
var_export($w);
echo "\n";
eval('switch (0) { default; echo "def\n"; }');
var_export($w);
echo "\n";
