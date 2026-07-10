<?php

declare(strict_types=1);

// Issue #17708 — password_needs_rehash(password_hash(...), ...) must match variable-bound form (ext/standard/password.c).

$h = password_hash('x', PASSWORD_BCRYPT);
$bound = password_needs_rehash($h, PASSWORD_BCRYPT, ['cost' => 4]);
$nested = password_needs_rehash(password_hash('x', PASSWORD_BCRYPT), PASSWORD_BCRYPT, ['cost' => 4]);

if (!\is_bool($bound)) {
    file_put_contents('php://stderr', 'variable-bound password_needs_rehash must return bool, got ' . get_debug_type($bound) . "\n");
    exit(1);
}
if (!\is_bool($nested)) {
    file_put_contents('php://stderr', 'nested password_needs_rehash must return bool, got ' . get_debug_type($nested) . "\n");
    exit(1);
}
if ($bound !== $nested) {
    file_put_contents('php://stderr', "nested and variable-bound results differ: bound=" . ($bound ? 'true' : 'false') . " nested=" . ($nested ? 'true' : 'false') . "\n");
    exit(1);
}

echo "ok\n";
