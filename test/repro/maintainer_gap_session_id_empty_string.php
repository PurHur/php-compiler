<?php

declare(strict_types=1);

// Issue #15250 — session_id('') returns previous id, not false (php-src ext/session/session.c).

$prev = session_id('');
if (false === $prev) {
    fwrite(STDERR, "FAIL: session_id('') returned false, expected previous id\n");
    exit(1);
}
if ('' !== $prev) {
    fwrite(STDERR, "FAIL: session_id('') with no prior id expected '', got ".var_export($prev, true)."\n");
    exit(1);
}

$before = session_id('abc123');
if ('' !== $before) {
    fwrite(STDERR, "FAIL: session_id('abc123') should return previous '', got ".var_export($before, true)."\n");
    exit(1);
}
if ('abc123' !== session_id()) {
    fwrite(STDERR, "FAIL: session_id() after set expected abc123\n");
    exit(1);
}

$again = session_id('');
if ('abc123' !== $again) {
    fwrite(STDERR, "FAIL: session_id('') should return previous abc123, got ".var_export($again, true)."\n");
    exit(1);
}
if ('abc123' !== session_id()) {
    fwrite(STDERR, "FAIL: session_id() after empty set should still be abc123\n");
    exit(1);
}

echo "ok\n";
