<?php
// #27661 — AOT by-ref sscanf with literal separators (was segfault)
$n = sscanf("2026-08-04", "%d-%d-%d", $y, $m, $d);
echo "$n:$y:$m:$d\n";
