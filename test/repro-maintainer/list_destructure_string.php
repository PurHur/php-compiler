<?php
// Repro for #7461 — list/array destructuring from string must TypeError not null slots.
try {
    list($a, $b) = 'ab';
    var_dump($a, $b);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
