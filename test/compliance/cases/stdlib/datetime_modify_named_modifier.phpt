--TEST--
stdlib DateTime::modify Zend stub modifier named param (#23685, ext/date/php_date.stub.php)
--FILE--
<?php
declare(strict_types=1);

foreach (['DateTime', 'DateTimeImmutable'] as $cls) {
    $names = [];
    foreach ((new ReflectionMethod($cls, 'modify'))->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $cls, '=', implode(',', $names), "\n";
}

$dt = new DateTime('2020-01-01');
echo 'modifier_named=', $dt->modify(modifier: '+1 day')->format('Y-m-d'), "\n";
echo 'positional=', (new DateTime('2020-01-01'))->modify('+1 day')->format('Y-m-d'), "\n";

try {
    (new DateTime('2020-01-01'))->modify(modify: '+1 day');
    echo "legacy_ok\n";
} catch (Throwable $e) {
    echo 'legacy: ', $e->getMessage(), "\n";
}
--EXPECT--
DateTime=modifier
DateTimeImmutable=modifier
modifier_named=2020-01-02
positional=2020-01-02
legacy: Unknown named parameter $modify
