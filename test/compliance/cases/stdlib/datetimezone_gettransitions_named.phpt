--TEST--
stdlib DateTimeZone::getTransitions Zend stub named params (#23666, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);

$rf = new ReflectionMethod('DateTimeZone', 'getTransitions');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'n=', $rf->getNumberOfParameters(), "\n";
echo 'params=', implode(',', $names), "\n";

$tz = new DateTimeZone('UTC');
$t = $tz->getTransitions(timestampBegin: 0, timestampEnd: 86400);
echo 'named=', is_array($t) ? count($t) : 'bad', "\n";

try {
    $tz->getTransitions(timestamp_begin: 0);
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo 'legacy: ', $e->getMessage(), "\n";
}
--EXPECT--
n=2
params=timestampBegin,timestampEnd
named=1
legacy: Unknown named parameter $timestamp_begin
