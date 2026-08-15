<?php
// #31118 — DateTime(Immutable)::setMicrosecond out-of-range is DateRangeError with given value.
foreach ([
    [new DateTime('2020-01-01'), 1000000],
    [new DateTimeImmutable('2020-01-01'), -1],
] as [$dt, $us]) {
    try {
        $dt->setMicrosecond($us);
        echo "ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
