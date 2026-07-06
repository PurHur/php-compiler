<?php

declare(strict_types=1);

// Maintainer gap #8851 — iconv() $string operand must TypeError on enum case (ext/iconv/iconv.c).
enum E: string { case A = 'x'; }
try {
    iconv('UTF-8', 'UTF-8', E::A);
    echo "uncaught\n";
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
    exit(0);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
    exit(1);
}
