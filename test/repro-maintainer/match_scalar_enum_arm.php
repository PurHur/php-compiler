<?php
declare(strict_types=1);

enum E: int { case A = 1; }

try {
    echo match (1) {
        E::A => 'hit',
    };
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

echo match (E::A) {
    E::A => 'ok',
}, "\n";
