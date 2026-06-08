<?php

enum E: string
{
    case A = 'tmp';
}

try {
    move_uploaded_file(E::A, '/tmp/x');
    echo "no error\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
