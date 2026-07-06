<?php
declare(strict_types=1);

// Issue #16836 — DateTime::getMicrosecond() gated on languageProfileVersion(), not VERSION.
if (getenv('PHP_COMPILER_PROFILE') !== '8.4') {
    putenv('PHP_COMPILER_PROFILE=8.4');
}

$dt = new DateTime('2024-06-01 12:34:56.789012');

if (!method_exists($dt, 'getMicrosecond')) {
    fwrite(STDERR, "fail: DateTime::getMicrosecond missing under PHP_COMPILER_PROFILE=8.4\n");
    exit(1);
}

$micro = $dt->getMicrosecond();
if ($micro !== 789012) {
    fwrite(STDERR, "fail: expected 789012, got {$micro}\n");
    exit(1);
}

echo "ok\n";
