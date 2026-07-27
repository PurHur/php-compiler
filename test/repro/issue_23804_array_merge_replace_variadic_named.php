<?php

/**
 * Repro #23804 — array_merge()/array_replace() variadic named args (#11349 follow-up).
 *
 * php-src: repeated variadic-slot names throw overwrite Error; single named → ArgumentCountError.
 * Run: php bin/vm.php test/repro/issue_23804_array_merge_replace_variadic_named.php
 */

try {
    array_merge(arrays: [1], arrays: [2]);
} catch (Throwable $e) {
    echo 'merge-named:', get_class($e), ': ', $e->getMessage(), "\n";
}

try {
    array_replace(array: [1 => 'a'], array: [1 => 'b']);
} catch (Throwable $e) {
    echo 'replace-named:', get_class($e), ': ', $e->getMessage(), "\n";
}

echo json_encode(array_merge([1], [2])), "\n";
echo json_encode(array_replace([1 => 'a'], [1 => 'b'])), "\n";
