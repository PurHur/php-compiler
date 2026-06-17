<?php
// Repro for #9240 — :never must TypeError on implicit return after non-terminal body.
function g(): never {
    echo "bad\n";
}
try {
    g();
    echo "continued\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
