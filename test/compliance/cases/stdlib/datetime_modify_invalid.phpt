--TEST--
DateTime::modify() invalid string returns false and leaves object unchanged (#10733)
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
--EXPECT--
array (
  0 => true,
  1 => '2020-01-02',
)
array (
  0 => false,
  1 => '2020-01-01',
)
