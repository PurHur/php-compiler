<?php
/**
 * Repro #23666 — DateTimeZone::getTransitions Zend stub named params.
 * php-src: ext/date/php_date.stub.php
 */
$rf = new ReflectionMethod('DateTimeZone', 'getTransitions');
echo 'n=', $rf->getNumberOfParameters(), "\n";
foreach ($rf->getParameters() as $p) {
    echo 'p=', $p->getName(), "\n";
}
$tz = new DateTimeZone('UTC');
try {
    $t = $tz->getTransitions(timestampBegin: 0, timestampEnd: 86400);
    echo 'named=', is_array($t) ? count($t) : 'bad', "\n";
} catch (Throwable $e) {
    echo 'named_fail=', $e->getMessage(), "\n";
}
try {
    $tz->getTransitions(timestamp_begin: 0, timestamp_end: 86400);
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo 'legacy=', $e->getMessage(), "\n";
}
