--TEST--
intlcal_create_instance Reflection + named timezone/locale (#27944)
--SKIPIF--
<?php if (!extension_loaded('intl')) die('skip host php-intl required'); ?>
--FILE--
<?php
$rf = new ReflectionFunction('intlcal_create_instance');
echo 'arity=', $rf->getNumberOfParameters(), PHP_EOL;
echo 'req=', $rf->getNumberOfRequiredParameters(), PHP_EOL;
echo 'ret=', $rf->hasReturnType() ? (string) $rf->getReturnType() : 'none', PHP_EOL;
foreach ($rf->getParameters() as $p) {
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
    $c = intlcal_create_instance(timezone: 'UTC', locale: 'en_US');
    echo 'named=', $c instanceof IntlCalendar ? get_class($c) : 'null', PHP_EOL;
} catch (Throwable $e) {
    echo 'named=', get_class($e), ': ', $e->getMessage(), PHP_EOL;
}
try {
    intlcal_create_instance(tz: 'UTC');
    echo "legacy_tz accepted\n";
} catch (Throwable $e) {
    echo $e->getMessage(), PHP_EOL;
}
$pos = intlcal_create_instance('UTC', 'en_US');
echo 'pos=', $pos instanceof IntlCalendar ? 'ok' : 'null', PHP_EOL;
?>
--EXPECT--
arity=2
req=0
ret=?IntlCalendar
p=timezone type=(none) opt=1 def=null
p=locale type=?string opt=1 def=null
named=IntlGregorianCalendar
Unknown named parameter $tz
pos=ok
