<?php
/**
 * #31101 — NestedJIT json_encode packed array commas (thin AOT).
 * Was "[12]" when $n++ did not stick across foreach; string latch → "[1,2]".
 */
$x = 1;
$a = [$x, $x + 1];
echo json_encode($a), "\n";
