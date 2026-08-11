<?php
// #30120 — $false->prop ??= must throw assign Error only (no read Warning).
$f = false;
try {
    $f->x ??= 1;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$n = null;
try {
    $n->x ??= 1;
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
