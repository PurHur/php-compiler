<?php
/** Repro #26704 — strtr() empty replace_pairs key must E_WARNING (php-src ext/standard/string.c). */
error_reporting(E_ALL);
$warns = [];
set_error_handler(static function (int $no, string $msg) use (&$warns): bool {
    $warns[] = $no . ':' . $msg;
    return true;
});
$out = strtr('ab', ['' => 'x', 'a' => 'A']);
echo 'out=' . $out . "\n";
echo 'warns=' . json_encode($warns) . "\n";

restore_error_handler();
error_clear_last();
@strtr('ab', ['' => 'x', 'a' => 'A']);
$e = error_get_last();
echo 'at_type=' . (null === $e ? 'null' : (string) $e['type']) . "\n";
echo 'at_msg=' . (null !== $e && str_contains((string) $e['message'], 'Ignoring replacement of empty string') ? 'yes' : 'no') . "\n";
