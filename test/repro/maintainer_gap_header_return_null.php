<?php
// php-src-strict repro for #12151 — header() after output returns null, not false.
echo 'x';
$r = header('Y: z');
if (null === $r && 'NULL' === gettype($r)) {
    echo "ok\n";
} else {
    echo 'fail ret=', var_export($r, true), ' type=', gettype($r), "\n";
}
