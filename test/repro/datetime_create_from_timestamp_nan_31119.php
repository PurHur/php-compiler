<?php
// #31119 — createFromTimestamp(NAN|INF|-INF) is DateRangeError with finite-range wording.
date_default_timezone_set('UTC');
foreach ([NAN, INF, -INF] as $ts) {
    try {
        DateTime::createFromTimestamp($ts);
        echo "ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ': ', $e->getMessage(), "\n";
    }
}
try {
    DateTimeImmutable::createFromTimestamp(NAN);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
echo DateTime::createFromTimestamp(123.456789)->format('Y-m-d H:i:s.u'), "\n";
