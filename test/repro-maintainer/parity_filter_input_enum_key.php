<?php

enum K: string
{
    case X = 'x';
}

try {
    filter_input(INPUT_GET, K::X, FILTER_DEFAULT);
    echo "no_exception\n";
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
