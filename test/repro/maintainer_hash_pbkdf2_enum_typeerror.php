<?php
declare(strict_types=1);

enum E: string { case A = 'secret'; }

try {
    hash_pbkdf2('sha256', E::A, 'salt', 1000);
    echo "ok\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
