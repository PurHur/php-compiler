--TEST--
stdlib sscanf()/fscanf() %i auto-base and %D alias (ext/standard/formatted_io.c; #11280)
--FILE--
<?php
declare(strict_types=1);
var_export(sscanf('077', '%i'));
echo "\n";
var_export(sscanf('0x10', '%i'));
echo "\n";
var_export(sscanf('42', '%i'));
echo "\n";
var_export(sscanf('99', '%D'));
echo "\n";
$fp = fopen('php://memory', 'r+');
fwrite($fp, '077');
rewind($fp);
var_export(fscanf($fp, '%i'));
echo "\n";
fclose($fp);
?>
--EXPECT--
array (
  0 => 63,
)
array (
  0 => 16,
)
array (
  0 => 42,
)
array (
  0 => 99,
)
array (
  0 => 63,
)
