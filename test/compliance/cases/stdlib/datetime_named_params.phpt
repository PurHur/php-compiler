--TEST--
stdlib DateTime named parameters — createFromFormat/constructor (#11785, ext/date/php_date.stub.php)
--FILE--
<?php
$dt = DateTime::createFromFormat(format: 'Y-m-d', datetime: '2020-01-02');
echo $dt->format('Y-m-d'), "\n";
$immutable = new DateTimeImmutable(datetime: '2020-03-04', timezone: new DateTimeZone('UTC'));
echo $immutable->format('Y-m-d'), "\n";
?>
--EXPECT--
2020-01-02
2020-03-04
