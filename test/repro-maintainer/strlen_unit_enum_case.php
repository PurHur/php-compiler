<?php
enum E { case A; }
try {
    echo strlen(E::A);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage();
}
