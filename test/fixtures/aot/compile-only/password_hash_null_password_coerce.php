<?php
// Compile-only (#17023): password_hash() null $password coerces to empty string (ext/standard/password.c Z_PARAM_STR).
$hash = password_hash(null, PASSWORD_DEFAULT);
echo \is_string($hash) && str_starts_with($hash, '$2y$') ? "hash_ok\n" : "hash_fail\n";
