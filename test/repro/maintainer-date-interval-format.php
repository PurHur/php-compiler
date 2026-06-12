<?php

declare(strict_types=1);

echo 'date_interval_format: ', function_exists('date_interval_format') ? 'yes' : 'no', "\n";
echo 'DateInterval: ', class_exists('DateInterval', false) ? 'yes' : 'no', "\n";

$interval = new DateInterval('P1D');
echo date_interval_format($interval, '%d'), "\n";
echo $interval->format('%y%m%d'), "\n";

$full = new DateInterval('P1Y2M3DT4H5M6S');
echo $full->format('%y %m %d %h %i %s'), "\n";
echo $full->format('%R%y:%m:%d %h:%i:%s'), "\n";

try {
    date_interval_format([], '%d');
    echo "uncaught type error\n";
} catch (\TypeError $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}

try {
    new DateInterval('bad');
    echo "uncaught bad spec\n";
} catch (\Exception $e) {
    echo $e::class, ': ', $e->getMessage(), "\n";
}
