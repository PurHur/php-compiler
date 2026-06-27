<?php

declare(strict_types=1);

// Repro for #12595 — restore_*_handler() on empty stack returns true (php-src-strict).
$fail = 0;
if (!restore_error_handler()) {
    echo "fail: restore_error_handler() empty stack expected true\n";
    ++$fail;
}
if (!restore_exception_handler()) {
    echo "fail: restore_exception_handler() empty stack expected true\n";
    ++$fail;
}
if (0 === $fail) {
    echo "ok\n";
}
