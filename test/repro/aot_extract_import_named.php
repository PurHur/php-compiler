<?php
// Repro #27520 — AOT extract() must compile and import named locals (not array_filter callback NestedJIT fail).
$arr = ['hello' => 'world', 'n' => 7];
extract($arr);
echo $hello, ':', $n, "\n";
