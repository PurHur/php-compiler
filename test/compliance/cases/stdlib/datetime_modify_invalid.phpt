--TEST--
stdlib DateTime::modify() returns false on invalid modifier (#10733)
--FILE--
<?php
$dt = new DateTime('2020-01-01');
$ok = $dt->modify('+1 day');
var_export([$ok instanceof DateTime, $dt->format('Y-m-d')]);
echo "\n";
$dt2 = new DateTime('2020-01-01');
$bad = $dt2->modify('not a date');
var_export([$bad, $dt2->format('Y-m-d')]);
echo "\n";
?>
--EXPECT--
PHP Warning:  DateTime::modify(): Failed to parse time string (not a date) at position 0 (n): The timezone could not be found in the database
array (
  0 => true,
  1 => '2020-01-02',
)
array (
  0 => false,
  1 => '2020-01-01',
)
