<?php
// #20174 — password_hash/verify(null) TypeError on 8.4 forward profile
// (php-src ext/standard/password.c Z_PARAM_STR(password))
foreach ([
    'password_hash' => static function (): void {
        password_hash(null, PASSWORD_DEFAULT);
    },
    'password_verify' => static function (): void {
        password_verify(null, 'x');
    },
] as $name => $fn) {
    try {
        $fn();
        echo "$name uncaught\n";
    } catch (TypeError $e) {
        echo $name, ' ', $e->getMessage(), "\n";
    }
}
