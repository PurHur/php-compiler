<?php

declare(strict_types=1);

// Repro for #12595 — empty-stack restore_*_handler() must return true (php-src).
$ok = true;
if (!restore_error_handler()) {
    echo "fail: restore_error_handler() empty stack expected true\n";
    $ok = false;
}
if (!restore_exception_handler()) {
    echo "fail: restore_exception_handler() empty stack expected true\n";
    $ok = false;
}
if ($ok) {
    echo "ok\n";
    exit(0);
}
exit(1);
