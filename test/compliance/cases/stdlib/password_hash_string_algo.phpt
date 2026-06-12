--TEST--
stdlib password_hash() — string|int $algo with ValueError for invalid names (#5039)
--FILE--
<?php
$hash = password_hash('secret', '2y');
echo is_string($hash) && strlen($hash) === 60 ? "2y_ok\n" : "2y_fail\n";

try {
    password_hash('x', 'not-an-algo');
    echo "invalid_ok\n";
} catch (ValueError $e) {
    echo "invalid_ve\n";
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
--EXPECT--
2y_ok
invalid_ve
bcrypt_ve
int99_ve
