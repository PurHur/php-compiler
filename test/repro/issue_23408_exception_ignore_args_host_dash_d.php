<?php
// Repro #23408 — host `php -d zend.exception_ignore_args=0 bin/vm.php` must include
// SensitiveParameter-wrapped getTrace() args (Zend/zend_exceptions.c).
function sp(#[\SensitiveParameter] string $password, int $other = 1): void {
    throw new Exception('boom');
}
try {
    sp('secret', 2);
} catch (Exception $e) {
    $f = $e->getTrace()[0] ?? [];
    echo array_key_exists('args', $f) ? "HAS_ARGS\n" : "NO_ARGS\n";
    if (isset($f['args'][0])) {
        echo get_debug_type($f['args'][0]), "\n";
    }
    $as = $e->getTraceAsString();
    echo str_contains($as, 'secret') ? "LEAKED\n" : "NO_LEAK\n";
    echo str_contains($as, 'Object(SensitiveParameterValue)') ? "WRAPPED\n" : "NOT_WRAPPED\n";
}
