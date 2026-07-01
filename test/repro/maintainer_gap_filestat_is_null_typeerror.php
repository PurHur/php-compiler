<?php

declare(strict_types=1);

// Issue #14620 — is_* filestat family rejects null under strict_types (ext/standard/filestat.c).
$errors = 0;
foreach (['is_readable', 'is_writable', 'is_executable', 'is_dir', 'is_link'] as $fn) {
    try {
        $fn(null);
        echo "fail: {$fn}(null) did not throw TypeError\n";
        ++$errors;
    } catch (TypeError) {
        // expected
    }
}
echo 0 === $errors ? "ok\n" : "fail\n";
exit($errors > 0 ? 1 : 0);
