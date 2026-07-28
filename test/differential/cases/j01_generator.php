<?php
// Ordinary PHP: a generator consumed by foreach. The corpus had one `yield` before this batch.
function g() { yield 1; yield 2; yield 3; }
$s = 0;
foreach (g() as $v) { $s += $v; }
echo $s, "\n";
