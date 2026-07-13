--TEST--
Language: nullsafe ?-> property fetch as direct call operand (#18455)
--FILE--
<?php
$o = null;
var_export($o?->prop);
echo "\n";
echo json_encode($o?->prop), "\n";
$x = $o?->prop;
var_export($x);
echo "\n";
var_export([$o?->prop]);
echo "\n";
--EXPECT--
NULL
null
NULL
array (
  0 => NULL,
)
