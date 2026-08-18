<?php
/**
 * AOT probe #23359 — named extension_loaded(extension:) / ob_start(callback:).
 * AOT ModuleRegistry currently advertises no extensions; this asserts named
 * dispatch binds (no Unknown named parameter), not Zend-loaded module lists.
 */
try {
    extension_loaded(extension: 'standard');
    echo "el_named_ok\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter')
        ? "el_named_rejected\n"
        : ('el_named_other='.get_class($e)."\n");
}
try {
    $started = ob_start(callback: null);
    echo 'inbuf';
    $buf = ob_get_clean();
    echo (true === $started && 'inbuf' === $buf) ? "ob_named_ok\n" : "ob_named_bad\n";
} catch (Throwable $e) {
    echo str_starts_with($e->getMessage(), 'Unknown named parameter')
        ? "ob_named_rejected\n"
        : ('ob_named_other='.get_class($e)."\n");
}
