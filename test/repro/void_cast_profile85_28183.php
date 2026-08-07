<?php
// Issue #28441 / #28183 — PROFILE=8.5: assignment (void) remains ParseError (statement-only)
try {
    eval('$b = (void)1; var_export($b);');
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
