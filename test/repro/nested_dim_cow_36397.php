<?php
// Nested dim write COW (#36397 / residual #34508) — by-value assign must not alias nested HT.
$b = ['x' => ['y' => 1]];
$a = $b;
$a['x']['y'] = 9;
echo $b['x']['y'], '|', $a['x']['y'], "\n";
