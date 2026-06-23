<?php
// Issue #10043 — random_int() min:/max: named parameters
$n = random_int(min: 1, max: 3);
echo ($n >= 1 && $n <= 3) ? "ok\n" : "fail\n";
