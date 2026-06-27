<?php
// #12745 — if (A !== x || B !== y) guard must match Zend when both sides mismatch.
$a = 2;
$b = 3;
if (1 !== $a || 1 !== $b) {
    echo "ok\n";
}

$mtime = $atime = 1782580551;
if (1000 !== $mtime || 900 !== $atime) {
    echo "touch-guard-ok\n";
}
