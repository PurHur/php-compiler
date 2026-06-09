<?php
declare(strict_types=1);

enum E: string
{
    case A = 'echo hi';
}

try {
    shell_exec(E::A);
    echo "ok\n";
} catch (Throwable $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
