<?php
/**
 * Repro #24950 — invalid radix digits must emit E_DEPRECATED (8192), not silence / E_USER_DEPRECATED.
 */
error_reporting(E_ALL);
$seen = [];
set_error_handler(static function (int $no, string $str) use (&$seen): bool {
    $seen[] = [$no, $str];
    return true;
});
echo hexdec('1g'), "\n";
echo json_encode($seen), "\n";
echo octdec('18'), ',', bindec('102'), ',', base_convert('1g', 16, 10), "\n";
echo json_encode($seen), "\n";
