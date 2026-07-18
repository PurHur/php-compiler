<?php
/** Repro for #20252 — ctype_*(null) TypeError under PROFILE=8.4. */
foreach (['ctype_alnum', 'ctype_digit', 'ctype_space', 'ctype_alpha'] as $f) {
    try {
        $r = $f(null);
        echo "$f COERCED ", var_export($r, true), "\n";
    } catch (Throwable $e) {
        echo "$f ", get_class($e), "\n";
    }
}
