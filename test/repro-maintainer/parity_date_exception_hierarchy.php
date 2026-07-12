<?php
declare(strict_types=1);

foreach ([
    'DateException',
    'DateMalformedStringException',
    'DateInvalidTimeZoneException',
    'DateInvalidOperationException',
] as $c) {
    echo $c, ':', class_exists($c) ? 'yes' : 'no', "\n";
}

$matrix = [
    ['DateMalformedStringException', fn () => new DateTime('not-a-date')],
    ['DateInvalidTimeZoneException', fn () => new DateTimeZone('Not/A/Zone')],
    ['DateInvalidTimeZoneException', fn () => date_default_timezone_set('Not/A/Zone')],
];
foreach ($matrix as [$expect, $fn]) {
    try {
        $fn();
        echo "$expect: no throw\n";
    } catch (Throwable $e) {
        echo "$expect:", get_class($e), "\n";
    }
}

try {
    new DateTime('not-a-date');
} catch (DateMalformedStringException $e) {
    echo "catch DateMalformedStringException ok\n";
}
