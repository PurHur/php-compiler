<?php
/**
 * Issue #16103 — password_hash(null, …) must throw TypeError (ext/standard/password.c Z_PARAM_STR).
 */
try {
    password_hash(null, PASSWORD_BCRYPT);
    fwrite(STDERR, "FAIL: expected TypeError, got hash\n");
    exit(1);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
    exit(0);
}
