<?php
/** Issue #7215 — constant() enum case operand TypeError must name enum class (basic_functions.c). */
enum E: string { case A = 'x'; }
try {
    constant(E::A);
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage() . "\n";
}
