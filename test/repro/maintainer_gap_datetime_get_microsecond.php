<?php
declare(strict_types=1);

/** Issue #14503 — DateTime::getMicrosecond() must not exist on PHP 8.2 reference profile. */

if (method_exists(DateTime::class, 'getMicrosecond')) {
    fwrite(STDERR, "fail: getMicrosecond exposed on 8.2 profile\n");
    exit(1);
}

if (method_exists(DateTimeImmutable::class, 'setMicrosecond')) {
    fwrite(STDERR, "fail: setMicrosecond exposed on 8.2 profile\n");
    exit(1);
}

$dt = new DateTime('2024-06-01 12:34:56.789012');
try {
    $dt->getMicrosecond();
    fwrite(STDERR, "fail: getMicrosecond callable on 8.2 profile\n");
    exit(1);
} catch (\Error $e) {
    if (!str_contains($e->getMessage(), 'getMicrosecond')) {
        fwrite(STDERR, 'fail: unexpected error: '.$e->getMessage()."\n");
        exit(1);
    }
}

echo "ok_no_method\n";
