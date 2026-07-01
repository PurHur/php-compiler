<?php

declare(strict_types=1);

$dt = date_create('2020-01-01');
$bad = date_modify($dt, 'not a date');
if (false !== $bad) {
    fwrite(STDERR, "expected false, got ".var_export($bad, true)."\n");
    exit(1);
}
if ('2020-01-01' !== $dt->format('Y-m-d')) {
    fwrite(STDERR, 'DateTime mutated on parse failure: '.$dt->format('Y-m-d')."\n");
    exit(1);
}
$ok = date_modify($dt, '+1 day');
if (false === $ok || '2020-01-02' !== $dt->format('Y-m-d')) {
    fwrite(STDERR, "valid modify failed\n");
    exit(1);
}
echo "ok\n";
