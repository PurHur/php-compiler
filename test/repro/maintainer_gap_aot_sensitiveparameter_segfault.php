<?php
/**
 * Repro #27333 / #27549 — AOT #[\SensitiveParameter] getTrace must wrap
 * SensitiveParameterValue (not segfault). php-src default ignore_args is On;
 * set Off so args are present (Zend/zend_exceptions.c).
 */
ini_set('zend.exception_ignore_args', '0');
function f(#[SensitiveParameter] string $p) {
    throw new Exception('x');
}
try {
    f('secret');
} catch (Throwable $e) {
    $a = $e->getTrace()[0]['args'][0] ?? null;
    echo is_object($a) ? get_class($a) : var_export($a, true), "\n";
}
