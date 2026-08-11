<?php
// Repro for #30142: $false->$prop *= / .= should emit assign Error only, no read Warning.
// Zend: Error: Attempt to assign property "x" on false (no prior Warning).

set_error_handler(function($code, $msg) {
    echo "WARNING: $msg\n";
    return true;
});

$f = false;
try {
    $f->x *= 2;
} catch (\Throwable $e) {
    echo get_class($e) . ": " . $e->getMessage() . "\n";
}

$f2 = false;
try {
    $f2->y .= 'a';
} catch (\Throwable $e) {
    echo get_class($e) . ": " . $e->getMessage() . "\n";
}
