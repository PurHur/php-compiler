<?php
// #26773 — AOT password_hash(PASSWORD_ARGON2ID, options) must compile and verify
$hash = password_hash('secret', PASSWORD_ARGON2ID, [
    'memory_cost' => 8,
    'time_cost' => 1,
    'threads' => 1,
]);
echo password_verify('secret', $hash) ? "ok\n" : "bad\n";
echo password_verify('no', $hash) ? "bad\n" : "ok\n";
