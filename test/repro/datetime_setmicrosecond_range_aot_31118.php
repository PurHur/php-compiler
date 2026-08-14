<?php
// #31118 — AOT DateTime::setMicrosecond out-of-range DateRangeError (single catch; no code after).
try {
    (new DateTime('2020-01-01'))->setMicrosecond(1000000);
    echo "ok\n";
} catch (Throwable $e) {
    echo ($e instanceof DateRangeError ? 'DateRangeError' : get_class($e)), ': ', $e->getMessage(), "\n";
}
