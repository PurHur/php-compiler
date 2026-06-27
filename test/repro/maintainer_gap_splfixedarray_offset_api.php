<?php
declare(strict_types=1);

// Repro for #12628 — SplFixedArray offset/count API (ext/spl/spl_fixedarray.c).
$a = new SplFixedArray(2);
if (count($a) !== 2) {
    echo 'fail: count expected 2, got ', count($a), PHP_EOL;
    exit(1);
}
if (isset($a[0])) {
    echo 'fail: isset uninitialized slot expected false', PHP_EOL;
    exit(1);
}
$a[0] = 'x';
if ($a[0] !== 'x') {
    echo 'fail: read expected x, got ', var_export($a[0], true), PHP_EOL;
    exit(1);
}
if (!isset($a[0])) {
    echo 'fail: isset after write expected true', PHP_EOL;
    exit(1);
}
echo 'ok', PHP_EOL;
