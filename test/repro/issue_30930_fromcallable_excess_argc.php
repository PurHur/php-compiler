<?php
// Repro for #30930 — Closure::fromCallable excess argc / missing argc
try {
    $c = Closure::fromCallable('strlen', 'x');
    echo 'OK:', $c('ab'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $c = Closure::fromCallable();
    echo 'OK0:', $c('ab'), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo 'ok=', Closure::fromCallable('strlen')('ab'), "\n";
