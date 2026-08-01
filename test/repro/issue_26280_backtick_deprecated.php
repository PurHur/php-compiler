<?php
// Issue #26280 repro — PROFILE=8.5 backtick shell-exec deprecation
$w = [];
set_error_handler(function ($n, $s) use (&$w) {
    $w[] = "$n:$s";
    return true;
});
$out = null;
eval('$out = `true`;');
var_export($w);
echo "\n";
$w = [];
$out2 = shell_exec('true');
var_export($w);
echo "\n";
