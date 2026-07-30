<?php
declare(strict_types=1);

date_default_timezone_set('UTC');
$base = strtotime('2020-01-15 12:00:00'); // Wed
foreach (['+1 weekday', '+2 weekdays', '-1 weekday', 'next weekday', 'previous weekday'] as $s) {
    $r = strtotime($s, $base);
    echo 'strtotime(', $s, ')=', var_export($r, true);
    if (is_int($r)) {
        echo ' ', date('Y-m-d H:i:s D', $r);
    }
    echo "\n";
    $dt = new DateTime('@'.$base);
    $dt->setTimezone(new DateTimeZone('UTC'));
    $m = @$dt->modify($s);
    if (false === $m) {
        echo "  modify: false\n";
    } else {
        echo '  modify: ', $dt->format('Y-m-d H:i:s D'), "\n";
    }
}
