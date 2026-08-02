<?php
/**
 * Repro #26796 — AOT #[SensitiveParameter] must not leak in (string)$e (Zend/VM/JIT).
 */
function f(#[\SensitiveParameter] string $password) {
    throw new Exception('boom');
}
try {
    f('secret');
} catch (Throwable $e) {
    echo str_contains((string) $e, 'secret') ? "LEAK\n" : "redacted\n";
}
