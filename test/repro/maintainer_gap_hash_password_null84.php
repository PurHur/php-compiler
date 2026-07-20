<?php
// #21314 — hash_equals TypeError; password_verify/needs_rehash soft-null on 8.4
// (php-src ext/hash/hash.c IS_STRING guard; ext/standard/password.c Z_PARAM_STR)
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    if (E_DEPRECATED === $no) {
        $seen[] = $str;
    }
    return true;
});

try {
    hash_equals(null, 'x');
    echo "hash_equals:OK\n";
} catch (TypeError $e) {
    echo "hash_equals:TE\n";
}

try {
    $r = password_verify(null, 'x');
    echo 'password_verify:', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo "password_verify:TE\n";
}

try {
    $r = password_needs_rehash(null, PASSWORD_DEFAULT);
    echo 'password_needs_rehash:', var_export($r, true), "\n";
} catch (TypeError $e) {
    echo "password_needs_rehash:TE\n";
}

restore_error_handler();
echo 'depr=', (int) (count($seen) >= 2), "\n";
echo "OK\n";
