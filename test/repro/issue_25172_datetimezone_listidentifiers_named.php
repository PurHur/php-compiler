<?php
declare(strict_types=1);

$r = new ReflectionMethod(DateTimeZone::class, 'listIdentifiers');
foreach ($r->getParameters() as $p) {
    echo $p->getName(),
        ' type=', $p->hasType() ? (string) $p->getType() : '-',
        ' null=', ($p->hasType() && $p->getType()->allowsNull()) ? '1' : '0',
        ' def=', $p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : '-',
        "\n";
}
$a = DateTimeZone::listIdentifiers(timezoneGroup: DateTimeZone::PER_COUNTRY, countryCode: 'US');
echo 'named n=', count($a), "\n";
try {
    DateTimeZone::listIdentifiers(what: DateTimeZone::PER_COUNTRY, country: 'US');
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo 'legacy: ', $e->getMessage(), "\n";
}
$b = DateTimeZone::listIdentifiers(DateTimeZone::PER_COUNTRY, 'US');
echo 'pos n=', count($b), "\n";
echo count($a) === count($b) ? "named_pos_sync\n" : "named_pos_mismatch\n";
