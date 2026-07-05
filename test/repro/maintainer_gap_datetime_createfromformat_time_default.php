<?php

declare(strict_types=1);

// Issue #16383 — date-only createFromFormat() fills unparsed time from current local time (ext/date/php_date.c).

$dt = DateTime::createFromFormat('Y-m-d', '2020-02-30');
if (false === $dt) {
    fwrite(STDERR, "createFromFormat failed\n");
    exit(1);
}
if ('2020-03-01' !== $dt->format('Y-m-d')) {
    fwrite(STDERR, 'date rollover mismatch: '.$dt->format('Y-m-d')."\n");
    exit(1);
}
if ($dt->format('H:i:s') !== date('H:i:s')) {
    fwrite(STDERR, 'time default mismatch: '.$dt->format('H:i:s').' vs '.date('H:i:s')."\n");
    exit(1);
}

$partial = DateTime::createFromFormat('Y-m-d H', '2020-01-01 14');
if ('14:00:00' !== $partial->format('H:i:s')) {
    fwrite(STDERR, 'partial time mismatch: '.$partial->format('H:i:s')."\n");
    exit(1);
}

echo "ok\n";
