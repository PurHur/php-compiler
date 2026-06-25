<?php
// Issue #11766 — password_hash() options cost numeric string coerces (ext/standard/password.c).
$h = password_hash('pw', PASSWORD_BCRYPT, ['cost' => '10']);
if (is_string($h) && str_starts_with($h, '$2y$10$') && password_verify('pw', $h)) {
    echo "ok\n";
} else {
    echo "fail\n";
}
