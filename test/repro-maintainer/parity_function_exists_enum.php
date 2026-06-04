<?php
declare(strict_types=1);

enum E: string { case A = 'strlen'; }

try {
    var_export(function_exists(E::A));
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
