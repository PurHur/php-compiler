<?php
declare(strict_types=1);

// Issue #7269 — SQLite3Exception hierarchy for ext/sqlite3 (pairs #3434).
if (!class_exists('SQLite3Exception', false)) {
    fwrite(STDERR, "fail: SQLite3Exception missing\n");
    exit(1);
}
if (!is_subclass_of('SQLite3Exception', 'Exception')) {
    fwrite(STDERR, "fail: SQLite3Exception must extend Exception\n");
    exit(1);
}
if (!extension_loaded('sqlite3')) {
    fwrite(STDERR, "fail: sqlite3 extension not loaded\n");
    exit(1);
}

try {
    throw new SQLite3Exception('probe');
} catch (SQLite3Exception $e) {
    if ('probe' !== $e->getMessage()) {
        fwrite(STDERR, "fail: catch message mismatch\n");
        exit(1);
    }
    echo "ok\n";
    exit(0);
}

fwrite(STDERR, "fail: catch did not run\n");
exit(1);
