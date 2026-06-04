<?php
declare(strict_types=1);
// Maintainer repro: escapeshellarg() enum case → TypeError (#5870, ext/standard/escapeshellarg.c)
enum E: string { case A = 'x'; }
try {
    escapeshellarg(E::A);
} catch (Throwable $t) {
    echo get_class($t), ': ', $t->getMessage(), "\n";
}
