<?php
enum E: string { case A = 'x'; }
try {
    foreach ([fn () => fnmatch(E::A, 'x')] as $call) {
        $call();
    }
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
