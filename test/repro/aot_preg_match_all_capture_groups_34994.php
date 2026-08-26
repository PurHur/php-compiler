<?php
// Issue #34994 — thin AOT preg_match_all with capture groups (PREG_PATTERN_ORDER).
$m = null;
$rc = preg_match_all('/a(b)/', 'ab ab', $m);
echo 'rc=', json_encode($rc), ' m=', json_encode($m), "\n";
$m2 = null;
$rc2 = preg_match_all('/(\w+)/', 'a b c', $m2);
echo 'rc2=', json_encode($rc2), ' m2=', json_encode($m2), "\n";
