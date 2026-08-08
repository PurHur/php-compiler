<?php
// Repro #29146 — dim ??= / []= on undefined CV must not leave Undefined variable on bare read.
error_reporting(E_ALL);
$a["x"] ??= 1;
var_export($a);
echo "\n";
$b["k"] = "y";
var_export($b);
echo "\n";
