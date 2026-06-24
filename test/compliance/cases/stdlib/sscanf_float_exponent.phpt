--TEST--
stdlib sscanf()/fscanf() %f scientific notation (ext/standard/formatted_io.c; #11210)
--FILE--
<?php
var_export(sscanf('1.5e2', '%f'));
echo "\n";
var_export(sscanf('1.5E-1', '%f'));
echo "\n";
var_export(sscanf('  2.5e+3xyz', '%f'));
echo "\n";
$fp = fopen('php://memory', 'r+');
fwrite($fp, '1.5e2');
rewind($fp);
var_export(fscanf($fp, '%f'));
echo "\n";
fclose($fp);
--EXPECT--
array (
  0 => 150.0,
)
array (
  0 => 0.15,
)
array (
  0 => 2500.0,
)
array (
  0 => 150.0,
)
