<?php
// Compile-only (#3397); nested match guard arms.
$x = 3;
echo match (true) {
    $x > 0 => 'pos',
    default => 'other',
}, "\n";
