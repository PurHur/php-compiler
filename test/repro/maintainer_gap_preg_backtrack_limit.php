<?php

declare(strict_types=1);

/**
 * Issue #12289 — preg_last_error() when pcre.backtrack_limit exhausted.
 */
@ini_set('pcre.backtrack_limit', '1');
@preg_match('/(a+)+b/', str_repeat('a', 100).'b');
$code = preg_last_error();
$msg = preg_last_error_msg();
@ini_restore('pcre.backtrack_limit');

if (2 !== $code || 'Backtrack limit exhausted' !== $msg) {
    fwrite(STDERR, "code={$code} msg={$msg}\n");
    exit(1);
}

echo "code={$code} msg={$msg}\n";
