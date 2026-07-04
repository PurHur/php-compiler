<?php
// Compile-only (#16103): password_hash() must lower null TypeError guards for AOT (ext/standard/password.c Z_PARAM_STR).
try {
    password_hash(null, PASSWORD_DEFAULT);
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
