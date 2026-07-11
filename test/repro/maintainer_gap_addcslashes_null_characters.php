<?php

declare(strict_types=1);

// Issue #17829 strict_types path: null $characters must TypeError (non-strict coerces in maintainer_gap_addcslashes_null_without_strict.php).
try {
    addcslashes('abc', null);
    echo "fail: addcslashes('abc', null) accepted null under strict_types\n";
    exit(1);
} catch (TypeError $e) {
    $expected = 'addcslashes(): Argument #2 ($characters) must be of type string, null given';
    if ($expected !== $e->getMessage()) {
        echo 'fail: addcslashes(null characters) got ', $e->getMessage(), "\n";
        exit(1);
    }
}
echo "ok\n";
