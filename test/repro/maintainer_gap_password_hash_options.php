<?php

/**
 * Repro for #10453 — password_hash() with PASSWORD_BCRYPT + options array.
 */

declare(strict_types=1);

$h0 = password_hash('test', PASSWORD_BCRYPT);
echo is_string($h0) ? "no-opt ok\n" : "no-opt fail\n";

$h1 = password_hash('test', PASSWORD_BCRYPT, ['cost' => 4]);
echo is_string($h1) ? "with-opt ok\n" : "with-opt fail\n";

$h2 = password_hash('test', 1, ['cost' => 4]);
echo is_string($h2) ? "lit-opt ok\n" : "lit-opt fail\n";
