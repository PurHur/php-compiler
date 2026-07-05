<?php
/**
 * Parity: error suppression on inline array literal dim-fetch (#16462).
 */
declare(strict_types=1);

$prev = error_reporting(E_ALL);
$result = @(['missing' => 1])['a'];
error_reporting($prev);

if (null !== $result) {
    fwrite(STDERR, "expected null for missing key under @, got " . var_export($result, true) . PHP_EOL);
    exit(1);
}

echo "ok\n";
