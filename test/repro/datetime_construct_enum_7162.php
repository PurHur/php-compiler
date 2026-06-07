<?php
enum E: string { case A = '2020-01-01'; }
try {
    new DateTime(E::A);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
