<?php
declare(strict_types=1);

// #5039 follow-up — invalid password_hash() $algo must ValueError like Zend (ext/standard/password.c).
try {
    password_hash('x', 99);
    echo "int99_ok\n";
} catch (ValueError $e) {
    echo "int99_ve\n";
}

try {
    password_hash('x', 'not-an-algo');
    echo "str_ok\n";
} catch (ValueError $e) {
    echo "str_ve\n";
}
