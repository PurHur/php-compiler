<?php

declare(strict_types=1);

try {
    $dt = new DateTime('2020-01-01T00:00:00+05:00');
    if ('2020-01-01T00:00:00+05:00' !== $dt->format('c')) {
        echo 'fail: datetime format='.$dt->format('c')."\n";
        exit(1);
    }
    $tz = new DateTimeZone('+0530');
    if ('+05:30' !== $tz->getName()) {
        echo 'fail: timezone name='.$tz->getName()."\n";
        exit(1);
    }
    echo "ok\n";
} catch (Throwable $e) {
    echo 'fail: '.get_class($e).': '.$e->getMessage()."\n";
    exit(1);
}
