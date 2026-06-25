<?php
declare(strict_types=1);
// Issue #11731 — crypt() $5$ SHA-256 salt must return full hash, not *0.

$salt = '$5$rounds=1000$usesomesillystringf';
$hash = crypt('pass', $salt);
$len = strlen($hash);
$prefixOk = str_starts_with($hash, '$5$');
echo 'len=' . $len . ' prefix=' . ($prefixOk ? 'yes' : 'no') . "\n";
if ($len < 60 || !$prefixOk) {
    exit(1);
}
