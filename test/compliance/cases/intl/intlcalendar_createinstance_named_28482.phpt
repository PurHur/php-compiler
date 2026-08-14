--TEST--
IntlCalendar::createInstance Reflection + named timezone (#28482)
--SKIPIF--
<?php if (!extension_loaded('intl')) die('skip host php-intl required'); ?>
--FILE--
<?php
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
try {
    IntlCalendar::createInstance(tz: 'UTC');
    echo "legacy_tz accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
$pos = IntlCalendar::createInstance('UTC');
echo 'pos=', $pos instanceof IntlCalendar ? 'ok' : 'null', PHP_EOL;
?>
--EXPECT--
arity=2
req=0
p=timezone type=(none) opt=1 def=null
p=locale type=?string opt=1 def=null
named=ok
Unknown named parameter $tz
pos=ok
