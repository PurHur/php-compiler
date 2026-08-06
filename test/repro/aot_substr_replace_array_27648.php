<?php
// Issue #27648 — AOT substr_replace() array $string + scalar replace/offset
$r = substr_replace(['ab', 'cd'], 'X', 1);
echo implode(',', $r), ' keys=', implode(',', array_keys($r)), "\n";
$r2 = substr_replace(['abcdef', '12345'], 'YZ', 2, 3);
echo implode(',', $r2), "\n";
