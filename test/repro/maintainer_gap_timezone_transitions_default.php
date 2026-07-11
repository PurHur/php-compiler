<?php

declare(strict_types=1);

$tz = new DateTimeZone('UTC');
$trans = timezone_transitions_get($tz);
if (!\is_array($trans) || [] === $trans) {
    fwrite(STDERR, "fail: expected non-empty transition list\n");
    exit(1);
}
echo 'count=', \count($trans), "\n";

$berlin = new DateTimeZone('Europe/Berlin');
$berlinTrans = timezone_transitions_get($berlin);
if (!\is_array($berlinTrans) || \count($berlinTrans) < 2) {
    fwrite(STDERR, "fail: expected multiple Berlin transitions\n");
    exit(1);
}
echo 'berlin_count=', \count($berlinTrans), "\n";
echo "ok\n";
