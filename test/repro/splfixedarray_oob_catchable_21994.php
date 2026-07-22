<?php
// Repro #21994 — SplFixedArray OOB dim write must be user-catchable
$a = new SplFixedArray(3);
try {
    $a[5] = 1;
    echo "no-throw\n";
} catch (Throwable $e) {
    echo 'caught:', get_class($e), "\n";
}
echo "after\n";
