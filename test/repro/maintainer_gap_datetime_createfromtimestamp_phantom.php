<?php

declare(strict_types=1);

if (method_exists(DateTime::class, 'createFromTimestamp')) {
    echo "fail: DateTime::createFromTimestamp registered on reference profile\n";
    exit(1);
}

if (method_exists(DateTimeImmutable::class, 'createFromTimestamp')) {
    echo "fail: DateTimeImmutable::createFromTimestamp registered on reference profile\n";
    exit(1);
}

echo "ok\n";
