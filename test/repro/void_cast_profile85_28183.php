<?php
// Issue #28183 — PROFILE=8.5 (void) cast must ParseError like Zend 8.5.8
try {
    eval('$b = (void)1; var_export($b);');
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
