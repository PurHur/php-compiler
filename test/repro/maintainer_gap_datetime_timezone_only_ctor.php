<?php

declare(strict_types=1);

/**
 * Maintainer repro: DateTime/DateTimeImmutable timezone:-only ctor (#12124).
 *
 * php-src: ext/date/php_date.c — php_date_initialize default $time = "now".
 */

$tz = new DateTimeZone('UTC');

try {
    $dt = new DateTime(timezone: $tz);
    if (!($dt instanceof DateTime)) {
        echo "fail: DateTime not instance\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo 'fail: DateTime '.get_class($e).': '.$e->getMessage()."\n";
    exit(1);
}

try {
    $immutable = new DateTimeImmutable(timezone: $tz);
    if (!($immutable instanceof DateTimeImmutable)) {
        echo "fail: DateTimeImmutable not instance\n";
        exit(1);
    }
} catch (Throwable $e) {
    echo 'fail: DateTimeImmutable '.get_class($e).': '.$e->getMessage()."\n";
    exit(1);
}

echo "ok\n";
