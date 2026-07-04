<?php
/** Maintainer repro #15875 — bare enum case arm must not resolve when scrutinee is E::A. */
enum E { case A; case B; }
try {
    echo match (E::A) {
        A => 'ok',
    };
} catch (Throwable $e) {
    echo get_class($e) . ': ' . $e->getMessage();
}
