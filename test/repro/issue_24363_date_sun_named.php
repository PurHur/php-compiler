<?php
/**
 * Repro #24363 — date_sun_* Zend stub named params.
 * php-src: ext/date/php_date.stub.php
 */
foreach (['date_sun_info', 'date_sunrise', 'date_sunset'] as $f) {
    echo $f, ':';
    foreach ((new ReflectionFunction($f))->getParameters() as $p) {
        echo ' ', $p->getName();
    }
    echo "\n";
}
$ts = 1577836800;
try {
    echo is_array(date_sun_info(timestamp: $ts, latitude: 31.5, longitude: 34.75)) ? "ts_ok\n" : "ts_bad\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    date_sun_info(time: $ts, latitude: 31.5, longitude: 34.75);
    echo "time_should_fail\n";
} catch (Throwable $e) {
    echo "time_rejected\n";
}
try {
    echo date_sunrise(timestamp: $ts, returnFormat: SUNFUNCS_RET_STRING, latitude: 31.5, longitude: 34.75), "\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    date_sunrise(time: $ts, format: SUNFUNCS_RET_STRING, latitude: 31.5, longitude: 34.75);
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo "legacy_rejected\n";
}
