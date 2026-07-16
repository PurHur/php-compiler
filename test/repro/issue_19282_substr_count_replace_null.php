<?php
// Repro #19282 — substr_count/substr_replace null under PHP_COMPILER_PROFILE=8.4 (no strict_types)
try {
    substr_count(null, 'a');
    echo "count:OK\n";
} catch (Throwable $e) {
    echo 'count: '.get_class($e)."\n";
}
try {
    substr_replace(null, 'x', 0);
    echo "replace:OK\n";
} catch (Throwable $e) {
    echo 'replace: '.get_class($e)."\n";
}
