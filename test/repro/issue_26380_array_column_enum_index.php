<?php
/**
 * #26380 — array_column() enum index_key TypeError message (Zend 8.4 shape).
 *
 * Default/reference profile: Illegal offset type (Zend 8.2).
 * PHP_COMPILER_PROFILE=8.4: Cannot access offset of type E on array.
 */
enum E: string { case A = 'a'; }
$rows = [['e' => E::A, 'n' => 1]];
try {
    array_column($rows, 'n', 'e');
    echo "uncaught\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
