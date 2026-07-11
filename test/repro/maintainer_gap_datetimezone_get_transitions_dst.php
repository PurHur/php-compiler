<?php

declare(strict_types=1);

$tz = new DateTimeZone('America/New_York');
$trans = $tz->getTransitions(strtotime('2020-03-01'), strtotime('2020-03-15'));

if (2 !== count($trans)) {
    echo 'fail: expected 2 transitions, got ', count($trans), "\n";
    exit(1);
}

if (!isset($trans[1]['isdst']) || true !== $trans[1]['isdst']) {
    echo 'fail: spring transition isdst expected true, got ';
    var_export($trans[1]['isdst'] ?? null);
    echo "\n";
    exit(1);
}

echo "ok\n";
