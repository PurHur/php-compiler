--TEST--
date_modify() invalid string returns false and leaves object unchanged (#14327)
--FILE--
<?php
$dt = date_create('2020-01-01');
$bad = date_modify($dt, 'not a date');
$afterBad = $dt->format('Y-m-d');
var_export([$bad, $afterBad]);
echo "\n";
$ok = date_modify($dt, '+1 day');
$afterOk = $dt->format('Y-m-d');
var_export([$ok instanceof DateTime, $afterOk]);
echo "\n";
--EXPECTF--
PHP Warning:  date_modify(): Failed to parse time string (not a date) at position 0 (n): The timezone could not be found in the database in %s on line %d
array (
  0 => false,
  1 => '2020-01-01',
)
array (
  0 => true,
  1 => '2020-01-02',
)
