<?php
/**
 * Repro for #24693: array_chunk()/array_reverse() null preserve_keys must TypeError.
 *
 * Expected: both calls throw TypeError matching Zend 8.2+.
 */

$errors = 0;

// array_chunk
try {
    $r = array_chunk([1, 2, 3], 2, null);
    echo "FAIL: array_chunk did not throw TypeError\n";
    $errors++;
} catch (\TypeError $e) {
    if (str_contains($e->getMessage(), 'preserve_keys') && str_contains($e->getMessage(), 'bool')) {
        echo "OK: array_chunk null preserve_keys → TypeError\n";
    } else {
        echo "FAIL: array_chunk TypeError message unexpected: {$e->getMessage()}\n";
        $errors++;
    }
}

// array_reverse
try {
    $r = array_reverse([1, 2], null);
    echo "FAIL: array_reverse did not throw TypeError\n";
    $errors++;
} catch (\TypeError $e) {
    if (str_contains($e->getMessage(), 'preserve_keys') && str_contains($e->getMessage(), 'bool')) {
        echo "OK: array_reverse null preserve_keys → TypeError\n";
    } else {
        echo "FAIL: array_reverse TypeError message unexpected: {$e->getMessage()}\n";
        $errors++;
    }
}

// Positive cases — valid bool still works
$r = array_chunk([1, 2, 3, 4], 2, true);
echo "OK: array_chunk true → " . json_encode($r) . "\n";

$r = array_reverse([1, 2, 3], false);
echo "OK: array_reverse false → " . json_encode($r) . "\n";

exit($errors > 0 ? 1 : 0);
