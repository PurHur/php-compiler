--TEST--
AOT array_column() — Enum::cases() name/value columns (#15393, ext/standard/array.c)
--FILE--
<?php
enum UnitE { case Alpha; case Beta; }
enum BackedE: string { case One = '1'; case Two = '2'; }
var_export(array_column(UnitE::cases(), 'name'));
echo "\n";
var_export(array_column(BackedE::cases(), 'name'));
echo "\n";
var_export(array_column(BackedE::cases(), 'value'));
echo "\n";
--EXPECT--
array (
  0 => 'Alpha',
  1 => 'Beta',
)
array (
  0 => 'One',
  1 => 'Two',
)
array (
  0 => '1',
  1 => '2',
)
