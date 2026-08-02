<?php
/**
 * Repro #26796 — AOT #[\SensitiveParameter] must not appear in (string)$Throwable.
 */
function f(#[\SensitiveParameter] string $password) {
    throw new Exception('boom');
}
try {
    f('secret');
} catch (Throwable $e) {
    echo str_contains((string) $e, 'secret') ? "LEAK\n" : "redacted\n";
}
