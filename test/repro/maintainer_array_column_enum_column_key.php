<?php

enum E: string
{
    case A = 'n';
}

try {
    array_column([['n' => 1]], E::A);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
