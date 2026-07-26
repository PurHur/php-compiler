--TEST--
stdlib date_create/date_create_immutable datetime:/timezone: named parameters (#23276, ext/date/php_date.stub.php)
--FILE--
<?php
date_default_timezone_set('UTC');
foreach (['date_create', 'date_create_immutable'] as $fn) {
    $rf = new ReflectionFunction($fn);
    $names = [];
    foreach ($rf->getParameters() as $p) {
        $names[] = $p->getName();
    }
    echo $fn, ':', implode(',', $names), "\n";
}
$dt = date_create(datetime: '2020-01-02', timezone: new DateTimeZone('UTC'));
echo $dt instanceof DateTime ? $dt->format('Y-m-d') : 'fail', "\n";
$immutable = date_create_immutable(datetime: '2020-03-04', timezone: new DateTimeZone('UTC'));
echo $immutable instanceof DateTimeImmutable ? $immutable->format('Y-m-d') : 'fail', "\n";
?>
--EXPECT--
date_create:datetime,timezone
date_create_immutable:datetime,timezone
2020-01-02
2020-03-04
