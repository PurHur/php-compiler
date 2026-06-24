--TEST--
stdlib fgetcsv() length: named parameter (#11105, ext/standard/file.c)
--FILE--
<?php
declare(strict_types=1);

$f = fopen('php://memory', 'r+');
fwrite($f, "a,b\n");
rewind($f);
$row = fgetcsv($f, length: 0);
var_export($row);
echo "\n";
rewind($f);
$pos = fgetcsv($f, 0);
var_export($row === $pos);
echo "\n";
fclose($f);
?>
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
true
