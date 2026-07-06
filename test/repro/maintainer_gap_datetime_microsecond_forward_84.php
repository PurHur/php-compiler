<?php
declare(strict_types=1);

if (!method_exists(DateTime::class, 'getMicrosecond')) {
    fwrite(STDERR, "fail: DateTime::getMicrosecond missing under forward profile\n");
    exit(1);
}

$dt = new DateTime('2024-06-01 12:34:56.789012');
if (789012 !== $dt->getMicrosecond()) {
    fwrite(STDERR, 'fail: expected 789012, got '.$dt->getMicrosecond()."\n");
    exit(1);
}

echo "ok\n";
