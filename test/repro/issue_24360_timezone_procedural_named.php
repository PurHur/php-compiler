<?php
/**
 * Repro #24360 — procedural timezone_* Zend stub named params.
 * php-src: ext/date/php_date.stub.php
 */
$tz = timezone_open('UTC');
$dt = new DateTime('2020-01-01', $tz);
foreach (['timezone_location_get', 'timezone_offset_get', 'timezone_transitions_get'] as $f) {
    echo $f, ':';
    foreach ((new ReflectionFunction($f))->getParameters() as $p) {
        echo ' ', $p->getName();
    }
    echo "\n";
}
try {
    echo is_array(timezone_location_get(object: $tz)) ? "loc_ok\n" : "loc_bad\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo timezone_offset_get(object: $tz, datetime: $dt), "\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
try {
    $t = timezone_transitions_get(object: $tz, timestampBegin: 0, timestampEnd: 86400);
    echo 'trans=', is_array($t) ? count($t) : 'bad', "\n";
} catch (Throwable $e) {
    echo $e->getMessage(), "\n";
}
