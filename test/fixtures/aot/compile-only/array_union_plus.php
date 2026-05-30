<?php
// Compile-only (#3690); native AOT array + not yet verified.
$r = ['a' => 1] + ['b' => 2];
echo $r['a'], "\n";
