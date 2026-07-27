<?php
// Issue #23911 — AOT str_pad() must not segfault (peer #23204 VmString NestedJIT null).
echo str_pad('p', 5, '-'), "\n";
$x = 5;
echo str_pad('p', $x, '-'), "\n";
$y = 3;
echo str_pad('p', $y + 2, '-'), "\n";
