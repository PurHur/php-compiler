<?php
// Maintainer repro for #12745 — OR guard with !== must enter block when either side mismatches.
$a = 2;
$b = 3;
if (1 !== $a || 1 !== $b) {
    echo "ok\n";
}

$mtime = $atime = 1782580551;
if (1000 !== $mtime || 900 !== $atime) {
    echo "touch-guard-ok\n";
}
