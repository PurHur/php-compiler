--TEST--
sscanf() %* assignment suppression scans without capturing (#9560, #22713)
--FILE--
<?php
var_export(sscanf('123 456', '%*d %d'));
echo "\n";
var_export(sscanf('abc 789', '%*s %d'));
echo "\n";
$a = $b = null;
echo sscanf('1 2 3', '%d%*d%d', $a, $b), "\n";
?>
--EXPECT--
array (
  0 => 456,
)
array (
  0 => 789,
)
3
