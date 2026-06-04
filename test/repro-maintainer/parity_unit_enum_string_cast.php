<?php
/** Maintainer repro for #5852 — (string) on unit enum case must Error (zend_enum.c). */
enum E { case A; }
try {
    var_dump((string) E::A);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
