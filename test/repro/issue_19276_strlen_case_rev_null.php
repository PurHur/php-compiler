<?php
// Repro #20007/#20154 (re-#19276) — strlen/case/rev soft-null; bin2hex TypeError under PROFILE=8.4
foreach (['strlen', 'strtolower', 'strtoupper', 'strrev', 'bin2hex'] as $f) {
    try {
        $r = $f(null);
        echo "$f:OK ", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo "$f: ".get_class($e)."\n";
    }
}
