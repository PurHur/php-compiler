<?php
declare(strict_types=1);

$r = new ReflectionFunction('timezone_identifiers_list');
foreach ($r->getParameters() as $p) {
    echo $p->getName(),
        ' opt=', $p->isOptional() ? '1' : '0',
        ' type=', $p->hasType() ? (string) $p->getType() : '-',
        ' null=', ($p->hasType() && $p->getType()->allowsNull()) ? '1' : '0',
        ' def=', $p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : '-',
        "\n";
}
echo 'omit=', count(timezone_identifiers_list()), "\n";
echo 'country_only=', count(timezone_identifiers_list(countryCode: 'US')), "\n";
echo 'both=', count(timezone_identifiers_list(timezoneGroup: DateTimeZone::PER_COUNTRY, countryCode: 'US')), "\n";
