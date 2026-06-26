<?php

declare(strict_types=1);

/**
 * Maintainer repro: DateTime/DateTimeImmutable named parameters (#11785).
 *
 * php-src: ext/date/php_date.stub.php — format/datetime/timezone stub names.
 */

$dt = DateTime::createFromFormat(format: 'Y-m-d', datetime: '2020-01-02');
if (!($dt instanceof DateTime) || '2020-01-02' !== $dt->format('Y-m-d')) {
    echo "fail: DateTime::createFromFormat named\n";
    exit(1);
}

$utc = new DateTimeZone('UTC');
$immutable = new DateTimeImmutable(datetime: '2020-03-04', timezone: $utc);
if (!($immutable instanceof DateTimeImmutable) || '2020-03-04' !== $immutable->format('Y-m-d')) {
    echo "fail: DateTimeImmutable constructor named\n";
    exit(1);
}

echo "ok\n";
