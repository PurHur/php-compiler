<?php
/** Repro #21503 — token_get_all(null) soft-null under PROFILE=8.4. */
error_reporting(E_ALL);
$deps = 0;
set_error_handler(static function (int $no, string $msg) use (&$deps): bool {
    if ($no === E_DEPRECATED) {
        ++$deps;
        echo "DEP\n";
    }
    return true;
});
try {
    $r = token_get_all(null);
    echo 'count=', count($r), "\n";
    echo $deps > 0 ? "OK\n" : "MISSING_DEP\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}
