<?php
// Repro #19273 / superseded by #21187 — soft-null (DEP+false) under PROFILE=8.4, not TypeError
foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $f) {
    try {
        $r = $f(null, 'x');
        echo "$f:OK=", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo "$f: ".get_class($e)."\n";
    }
}
