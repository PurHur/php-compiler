<?php
declare(strict_types=1);

/** Issue #13761 — array_merge_recursive inline array_keys() must preserve string-key order. */

$merged = array_merge_recursive(['a' => 1], array_keys(['b' => 2]));
$keys = array_keys($merged);
$expected = ['a', 0];
if ($keys !== $expected) {
    fwrite(STDERR, 'fail: key order '.var_export($keys, true).' expected '.var_export($expected, true)."\n");
    exit(1);
}
if ($merged['a'] !== 1 || $merged[0] !== 'b') {
    fwrite(STDERR, 'fail: values '.var_export($merged, true)."\n");
    exit(1);
}

$overlay = array_keys(['b' => 2]);
$varMerged = array_merge_recursive(['a' => 1], $overlay);
if ($varMerged !== $merged) {
    fwrite(STDERR, 'fail: variable overlay '.var_export($varMerged, true)."\n");
    exit(1);
}

echo "ok\n";
