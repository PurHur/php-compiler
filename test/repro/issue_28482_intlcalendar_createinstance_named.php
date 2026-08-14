<?php
/**
 * IntlCalendar::createInstance Reflection + named $timezone (#28482).
 *
 * php-src: ext/intl/calendar/calendar.stub.php
 *   createInstance($timezone = null, ?string $locale = null): ?IntlCalendar
 */
$rm = new ReflectionMethod('IntlCalendar', 'createInstance');
echo 'arity=', $rm->getNumberOfParameters(), PHP_EOL;
echo 'req=', $rm->getNumberOfRequiredParameters(), PHP_EOL;
foreach ($rm->getParameters() as $p) {
    $t = $p->getType();
    echo 'p=', $p->getName();
    echo ' type=', $t ? (string) $t : '(none)';
    echo ' opt=', $p->isOptional() ? '1' : '0';
    if ($p->isDefaultValueAvailable()) {
        echo ' def=', json_encode($p->getDefaultValue());
    }
    echo PHP_EOL;
}
try {
    $c = IntlCalendar::createInstance(timezone: 'UTC');
    echo 'named=', $c instanceof IntlCalendar ? 'ok' : 'null', PHP_EOL;
} catch (Throwable $e) {
    echo 'named=', get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
$pos = IntlCalendar::createInstance('UTC');
echo 'pos=', $pos instanceof IntlCalendar ? 'ok' : 'null', PHP_EOL;
