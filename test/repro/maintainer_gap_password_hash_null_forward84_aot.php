<?php
// #21210 — AOT soft-null password_hash(null) on PROFILE=8.4 (no TypeError)
$p = null;
try {
    $h = password_hash($p, PASSWORD_DEFAULT);
    echo (false === $h || is_string($h)) ? "OK\n" : "BAD\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
