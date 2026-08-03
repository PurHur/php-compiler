<?php
/**
 * Repro #27333 — AOT #[\SensitiveParameter] getTrace must wrap SensitiveParameterValue
 * (not segfault). Default zend.exception_ignore_args is Off; AOT ini_set of that key
 * is a separate NestedJIT abort on master (not required for this Done-when).
 */
function f(#[SensitiveParameter] string $p) {
    throw new Exception('x');
}
try {
    f('secret');
} catch (Throwable $e) {
    $a = $e->getTrace()[0]['args'][0] ?? null;
    echo is_object($a) ? get_class($a) : var_export($a, true), "\n";
}
