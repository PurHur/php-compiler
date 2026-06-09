<?php

declare(strict_types=1);

// Compile-only (#4530): fputcsv() CSV option ValueError lowering for AOT lint.
$fp = fopen('php://memory', 'r+');
try {
    fputcsv($fp, ['a'], ',', '', '\\');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
