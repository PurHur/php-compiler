<?php

declare(strict_types=1);

enum S: string {
    case A = 'secret';
}

try {
    password_hash(S::A, PASSWORD_DEFAULT);
    echo "OK\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
