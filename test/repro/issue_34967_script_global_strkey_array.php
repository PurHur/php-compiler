<?php
// #34967 — top-level string-keyed INIT_ARRAY must compile under AOT
$r = ['k' => 'K'];
echo $r['k'], "\n";

function f($a, $b)
{
    echo "$a|$b\n";
}
$x = 5;
f($x + 1, $r['k']);
