<?php
/**
 * Issue #7135: array_map() string callback must pass enum cases to callee (TypeError, not abort).
 */
enum E: string
{
    case A = 'x';
}
try {
    var_export(array_map('strlen', [E::A]));
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
