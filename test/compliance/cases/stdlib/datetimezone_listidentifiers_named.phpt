--TEST--
stdlib DateTimeZone::listIdentifiers Zend stub named params (#25172, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);

$rf = new ReflectionMethod('DateTimeZone', 'listIdentifiers');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName()
        .':'
        .($p->hasType() ? (string) $p->getType() : '-')
        .':'
        .($p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : '-');
}
echo 'params=', implode(',', $names), "\n";

$named = DateTimeZone::listIdentifiers(timezoneGroup: DateTimeZone::PER_COUNTRY, countryCode: 'US');
$pos = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'US');
echo 'named=', count($named), "\n";
echo 'pos=', count($pos), "\n";
echo count($named) === count($pos) ? "sync\n" : "mismatch\n";

try {
    DateTimeZone::listIdentifiers(what: DateTimeZone::PER_COUNTRY);
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo 'legacy: ', $e->getMessage(), "\n";
}
--EXPECT--
params=timezoneGroup:int:2047,countryCode:?string:null
named=29
pos=29
sync
legacy: Unknown named parameter $what
