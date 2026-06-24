<?php
// Maintainer repro (#11249): array set ops must Error on object elements (php-src array.c).
$o = new stdClass();
$fail = 0;
foreach (['array_intersect', 'array_diff', 'array_diff_assoc'] as $fn) {
    try {
        $fn([$o], [$o]);
        echo "FAIL {$fn}: expected Error, got success\n";
        ++$fail;
    } catch (Throwable $e) {
        if (!($e instanceof Error)
            || 'Object of class stdClass could not be converted to string' !== $e->getMessage()) {
            echo "FAIL {$fn}: ", get_class($e), ': ', $e->getMessage(), "\n";
            ++$fail;
        }
    }
}
if (0 !== $fail) {
    exit(1);
}
echo "ok\n";
