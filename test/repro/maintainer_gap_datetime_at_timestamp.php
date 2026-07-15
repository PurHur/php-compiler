<?php

date_default_timezone_set('America/New_York');

function expect(bool $ok, string $msg): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL: {$msg}\n");
        exit(1);
    }
}

function assertEpochUtc(DateTimeInterface $dt, string $label): void
{
    expect($dt->getTimestamp() === 0, "{$label}: timestamp must be 0");
    expect($dt->format('c') === '1970-01-01T00:00:00+00:00', "{$label}: format('c') must be epoch +00:00");
    expect($dt->getTimezone()->getName() === '+00:00', "{$label}: timezone must be +00:00");
}

$dt = date_create('@0');
expect($dt instanceof DateTimeInterface, "date_create('@0') must return DateTimeInterface");
assertEpochUtc($dt, "date_create('@0')");

$tzUtc = new DateTimeZone('UTC');
$dt2 = new DateTime('@0', $tzUtc);
assertEpochUtc($dt2, "new DateTime('@0', UTC)");

$di = new DateTimeImmutable('@0');
assertEpochUtc($di, "new DateTimeImmutable('@0')");

echo "OK\n";

