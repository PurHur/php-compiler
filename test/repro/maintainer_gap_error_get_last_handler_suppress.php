<?php

declare(strict_types=1);

// Maintainer gap #12300 — error_get_last() null when custom error handler suppresses (basic_functions.c).

set_error_handler(static fn (): bool => true);

@trigger_error('suppressed notice', E_USER_NOTICE);
$last = error_get_last();
if (null !== $last) {
    fwrite(STDERR, 'error_get_last(): expected null after suppressed E_USER_NOTICE, got '.var_export($last, true)."\n");
    exit(1);
}

trigger_error('suppressed notice 2', E_USER_NOTICE);
$last2 = error_get_last();
if (null !== $last2) {
    fwrite(STDERR, 'error_get_last(): expected null after handler-suppressed trigger_error, got '.var_export($last2, true)."\n");
    exit(1);
}

echo "ok\n";
