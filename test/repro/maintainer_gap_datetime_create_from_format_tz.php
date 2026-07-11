<?php

declare(strict_types=1);

$dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s e', '2024-06-01 12:00:00 UTC');
if (false === $dt) {
    echo "create_from_format_tz_fail:false\n";
    exit(1);
}
if ('UTC' !== $dt->format('e')) {
    echo 'create_from_format_tz_fail:'.$dt->format('e')."\n";
    exit(1);
}
if ('2024-06-01 12:00:00' !== $dt->format('Y-m-d H:i:s')) {
    echo 'create_from_format_time_fail:'.$dt->format('Y-m-d H:i:s')."\n";
    exit(1);
}

$dt2 = DateTimeImmutable::createFromFormat('Y-m-d H:i:s T', '2024-06-01 12:00:00 UTC');
if (false === $dt2 || 'UTC' !== $dt2->format('e')) {
    echo "create_from_format_T_fail\n";
    exit(1);
}

echo "create_from_format_tz_ok\n";
