<?php
// #26773 — AOT Module->verify must accept password_hash(PASSWORD_ARGON2ID, options)
// (options-cost PHI predecessors / dominate-uses). Runtime verify is a separate follow-up.
$hash = password_hash('secret', PASSWORD_ARGON2ID, [
    'memory_cost' => 8,
    'time_cost' => 1,
    'threads' => 1,
]);
echo is_string($hash) ? "hash\n" : "false\n";
