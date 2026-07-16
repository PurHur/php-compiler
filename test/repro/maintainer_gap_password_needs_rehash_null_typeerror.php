<?php
// #18655 — password_needs_rehash(null) TypeError on 8.4 forward profile
// (php-src ext/standard/password.c Z_PARAM_STR(hash))
try {
    password_needs_rehash(null, PASSWORD_DEFAULT);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
