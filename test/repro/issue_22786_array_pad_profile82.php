<?php
/**
 * #22786 — ARRAY_PAD_* + array_pad() 4th $pad_type are PHP 8.4+ only.
 * Run: PHP_COMPILER_PROFILE=8.2 php bin/vm.php test/repro/issue_22786_array_pad_profile82.php
 */
foreach (['ARRAY_PAD_LEFT', 'ARRAY_PAD_RIGHT', 'ARRAY_PAD_BOTH'] as $c) {
    echo $c, '=', defined($c) ? '1' : '0', "\n";
}
try {
    array_pad([1], 3, 0, 0);
    echo "no error\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
