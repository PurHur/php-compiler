<?php
// Repro #19276 — strlen/case/rev null under PHP_COMPILER_PROFILE=8.4 (no strict_types)
foreach (['strlen', 'strtolower', 'strtoupper', 'strrev'] as $f) {
    try {
        $f(null);
        echo "$f:OK\n";
    } catch (Throwable $e) {
        echo "$f: ".get_class($e)."\n";
    }
}
