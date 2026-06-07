<?php
enum E: string { case A = 'x'; }
function cb(array $m): string {
    return 'y';
}
try {
    preg_replace_callback('/x/', 'cb', E::A);
    echo "no_exception\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
