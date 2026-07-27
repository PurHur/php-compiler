<?php
// Ordinary PHP: foreach by value. The corpus had NO foreach at all before this batch — it grew
// from targeted bug hunts, so it covered expression shapes densely and everyday control flow not
// at all. Passes both backends.
$a = [10, 20, 30];
$s = 0;
foreach ($a as $v) { $s += $v; }
echo $s, "\n";
