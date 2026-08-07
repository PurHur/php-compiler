--TEST--
DateTime::modify() invalid string throws DateMalformedStringException on forward 8.4 profile (#28524, ext/date/php_date.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
$dt = new DateTime('2020-01-01');
$ok = $dt->modify('+1 day');
var_export([$ok instanceof DateTime, $dt->format('Y-m-d')]);
echo "\n";
$dt2 = new DateTime('2020-01-01');
try {
    $dt2->modify('not a date');
    echo "no throw\n";
} catch (DateMalformedStringException $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
    echo $dt2->format('Y-m-d'), "\n";
}
--EXPECT--
array (
  0 => true,
  1 => '2020-01-02',
)
DateMalformedStringException
DateTime::modify(): Failed to parse time string (not a date) at position 0 (n): The timezone could not be found in the database
2020-01-01
