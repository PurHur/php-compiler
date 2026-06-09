<?php

enum S: string
{
    case X = 'ab';
}

try {
    str_split(S::X);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
