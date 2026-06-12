<?php
// Zend parity: password_hash() string|int $algo (ext/standard/password.c, issue #5039).

$hash = password_hash('secret', '2y');
echo is_string($hash) && strlen($hash) === 60 ? "2y_ok\n" : "2y_fail\n";

try {
    password_hash('x', 'not-an-algo');
    echo "invalid_ok\n";
} catch (ValueError $e) {
    echo $e->getMessage(), "\n";
}

try {
    password_hash('x', 'bcrypt');
    echo "bcrypt_ok\n";
} catch (ValueError $e) {
    echo "bcrypt_ve\n";
}

try {
    password_hash('x', 99);
    echo "int99_ok\n";
} catch (ValueError $e) {
    echo "int99_ve\n";
}
