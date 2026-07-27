--TEST--
date date_default_timezone_set / timezone_identifiers_list Zend stub named params JIT (#23446, ext/date/php_date.stub.php)
--JIT--
--FILE--
<?php
$rf = new ReflectionFunction('date_default_timezone_set');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'date_default_timezone_set:', implode(',', $names), "\n";

$rf = new ReflectionFunction('timezone_identifiers_list');
$names = [];
foreach ($rf->getParameters() as $p) {
    $names[] = $p->getName();
}
echo 'timezone_identifiers_list:', implode(',', $names), "\n";

echo date_default_timezone_set(timezoneId: 'UTC') ? "set_ok\n" : "set_bad\n";
try {
    date_default_timezone_set(timezone_identifier: 'UTC');
    echo "set_legacy=ok\n";
} catch (Error $e) {
    echo 'set_legacy=', $e->getMessage(), "\n";
}

$europe = timezone_identifiers_list(timezoneGroup: DateTimeZone::EUROPE);
echo 'europe=', count($europe) > 0 ? 'ok' : 'empty', "\n";
try {
    timezone_identifiers_list(what: DateTimeZone::EUROPE);
    echo "list_legacy=ok\n";
} catch (Error $e) {
    echo 'list_legacy=', $e->getMessage(), "\n";
}

$de = timezone_identifiers_list(timezoneGroup: DateTimeZone::PER_COUNTRY, countryCode: 'DE');
echo 'de=', count($de) > 0 ? 'ok' : 'empty', "\n";
?>
--EXPECT--
date_default_timezone_set:timezoneId
timezone_identifiers_list:timezoneGroup,countryCode
set_ok
set_legacy=Unknown named parameter $timezone_identifier
europe=ok
list_legacy=Unknown named parameter $what
de=ok
