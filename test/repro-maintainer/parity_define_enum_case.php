<?php
enum E: string {
    case A = 'x';
}

foreach ([E::A, 'E::A'] as $name) {
    try {
        define($name, 1);
        echo "no throw\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
