<?php
/**
 * #25818 — PASSWORD_ARGON2I/ID must be strings 'argon2i'/'argon2id' (php-src password.c),
 * not internal VmPassword int ids 2/3. Assert via is_string/=== (not var_export alone).
 */
if (!defined('PASSWORD_ARGON2I') || !defined('PASSWORD_ARGON2ID')) {
    echo "skip argon2 unavailable\n";
    exit(0);
}

$ok = true;

$bcrypt = PASSWORD_BCRYPT;
if (!is_string($bcrypt) || $bcrypt !== '2y' || gettype($bcrypt) !== 'string') {
    echo 'FAIL ConstFetch PASSWORD_BCRYPT: type=', gettype($bcrypt), "\n";
    $ok = false;
}

$argon2i = PASSWORD_ARGON2I;
if (!is_string($argon2i) || $argon2i !== 'argon2i' || gettype($argon2i) !== 'string') {
    echo 'FAIL ConstFetch PASSWORD_ARGON2I: type=', gettype($argon2i), ' val=', (string) $argon2i, "\n";
    $ok = false;
}

$argon2id = PASSWORD_ARGON2ID;
if (!is_string($argon2id) || $argon2id !== 'argon2id' || gettype($argon2id) !== 'string') {
    echo 'FAIL ConstFetch PASSWORD_ARGON2ID: type=', gettype($argon2id), ' val=', (string) $argon2id, "\n";
    $ok = false;
}

if (constant('PASSWORD_ARGON2I') !== 'argon2i' || constant('PASSWORD_ARGON2ID') !== 'argon2id') {
    echo "FAIL constant() lookup\n";
    $ok = false;
}

$hash = password_hash('x', PASSWORD_ARGON2ID);
if (!is_string($hash) || !str_starts_with($hash, '$argon2id$') || !password_verify('x', $hash)) {
    echo "FAIL password_hash(PASSWORD_ARGON2ID)\n";
    $ok = false;
}

echo $ok ? "OK\n" : "FAIL\n";
exit($ok ? 0 : 1);
