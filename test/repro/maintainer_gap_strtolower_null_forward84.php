<?php
// Repro #20007 — strlen/case/rev/bin2hex null coerce under PHP_COMPILER_PROFILE=8.4
foreach (['strlen', 'strtolower', 'strtoupper', 'strrev', 'bin2hex'] as $f) {
    try {
        $r = $f(null);
        echo "$f:OK ", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo "$f: ".get_class($e)."\n";
    }
}
