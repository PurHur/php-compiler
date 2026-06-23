--TEST--
stdlib sscanf() %e and %g float conversions (#10830, ext/standard/formatted_io.c)
--FILE--
<?php
var_export(sscanf('1.5e2', '%e'));
echo "\n";
var_export(sscanf('3.14', '%g'));
echo "\n";
var_export(sscanf('-2.5E-1', '%E'));
echo "\n";
--EXPECT--
array (
  0 => 150.0,
)
array (
  0 => 3.14,
)
array (
  0 => -0.25,
)
