--TEST--
stdlib timezone_identifiers_list Reflection + countryCode-only named (#25173, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);

$rf = new ReflectionFunction('timezone_identifiers_list');
$parts = [];
foreach ($rf->getParameters() as $p) {
    $parts[] = $p->getName()
        .':opt='.($p->isOptional() ? '1' : '0')
        .':'.($p->hasType() ? (string) $p->getType() : '-')
        .':'.($p->isDefaultValueAvailable() ? json_encode($p->getDefaultValue()) : '-');
}
echo 'params=', implode(',', $parts), "\n";
$omit = count(timezone_identifiers_list());
$countryOnly = count(timezone_identifiers_list(countryCode: 'US'));
$both = count(timezone_identifiers_list(timezoneGroup: DateTimeZone::PER_COUNTRY, countryCode: 'US'));
echo 'omit=', $omit, "\n";
echo 'country_only=', $countryOnly, "\n";
echo 'both=', $both, "\n";
echo $omit === $countryOnly ? "country_only_is_all\n" : "country_only_mismatch\n";
--EXPECT--
params=timezoneGroup:opt=1:int:2047,countryCode:opt=1:?string:null
omit=419
country_only=419
both=29
country_only_is_all
