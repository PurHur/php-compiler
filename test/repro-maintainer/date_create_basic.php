<?php
declare(strict_types=1);

$dt = date_create('2026-06-01', new DateTimeZone('UTC'));
if (false === $dt) {
    fwrite(STDERR, "parse failed\n");
    exit(1);
}
echo $dt->format('Y-m-d'), "\n";

$di = date_create_immutable('2026-06-01 12:00:00', new DateTimeZone('UTC'));
echo $di->format('Y-m-d H:i:s'), "\n";

var_export(date_create('not-a-date'));
echo "\n";
