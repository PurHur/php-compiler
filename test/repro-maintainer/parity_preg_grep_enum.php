<?php
enum E: string { case A = 'foo'; case B = 'bar'; }
try {
    preg_grep('/^f/', [E::A, E::B]);
    echo "no throw\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
