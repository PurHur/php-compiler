--TEST--
stdlib fgetcsv() null $length — unlimited read like Zend (#16493, ext/standard/file.c)
--FILE--
<?php
$fp = fopen('php://memory', 'r+');
fwrite($fp, "a,b\n");
rewind($fp);
$row = fgetcsv($fp, null, ',');
var_export($row);
echo "\n";
rewind($fp);
$omitted = fgetcsv($fp, separator: ',');
var_export($row === $omitted);
echo "\n";
fclose($fp);
?>
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
true
