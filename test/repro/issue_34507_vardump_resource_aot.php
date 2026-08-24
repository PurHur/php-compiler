<?php
// Repro #34507: thin AOT var_dump/print_r(stream) must not print bare int(N).
$f = fopen('php://memory', 'r');
var_dump($f);
echo print_r($f, true), "\n";
fclose($f);
var_dump($f);
echo print_r($f, true), "\n";
