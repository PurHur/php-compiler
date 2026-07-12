<?php

declare(strict_types=1);

/**
 * Issue #18388 — DateTime('@unix') getTimezone()->getName() must be +00:00 not UTC.
 */

$mutable = new DateTime('@1609459200');
$immutable = new DateTimeImmutable('@0');

$mutableName = $mutable->getTimezone()->getName();
$immutableName = $immutable->getTimezone()->getName();

if ('+00:00' !== $mutableName) {
    fwrite(STDERR, "FAIL: mutable getTimezone()->getName() = {$mutableName}, expected +00:00\n");
    exit(1);
}
if ('+00:00' !== $immutableName) {
    fwrite(STDERR, "FAIL: immutable getTimezone()->getName() = {$immutableName}, expected +00:00\n");
    exit(1);
}

$encoded = json_encode($mutable);
$expectedJson = '{"date":"2021-01-01 00:00:00.000000","timezone_type":1,"timezone":"+00:00"}';
if ($encoded !== $expectedJson) {
    fwrite(STDERR, "FAIL: json_encode = {$encoded}, expected {$expectedJson}\n");
    exit(1);
}

echo "ok\n";
