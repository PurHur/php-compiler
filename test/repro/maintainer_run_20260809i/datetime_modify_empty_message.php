<?php
error_reporting(E_ALL);
set_error_handler(function ($errno, $errstr) {
    echo "ERR:$errno:$errstr\n";
    return true;
});
foreach ([
    ['DateTime', new DateTime('2020-01-01')],
    ['DateTimeImmutable', new DateTimeImmutable('2020-01-01')],
] as [$label, $d]) {
    try {
        $d->modify('');
        echo "$label: no throw\n";
    } catch (Throwable $e) {
        echo "$label:", get_class($e), ':', $e->getMessage(), "\n";
    }
    try {
        $d2 = $label === 'DateTime' ? new DateTime('2020-01-01') : new DateTimeImmutable('2020-01-01');
        $d2->modify(null);
        echo "$label-null: no throw\n";
    } catch (Throwable $e) {
        echo "$label-null:", get_class($e), ':', $e->getMessage(), "\n";
    }
}
