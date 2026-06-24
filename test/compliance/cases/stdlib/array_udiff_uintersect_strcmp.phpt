--TEST--
stdlib array_udiff()/array_uintersect() strcmp string callback (#11057)
--FILE--
<?php
var_export(array_udiff([1, 2], [2], 'strcmp'));
echo "\n";
var_export(array_uintersect([1, 2], [2], 'strcmp'));
echo "\n";
--EXPECT--
array (
  0 => 1,
)
array (
  1 => 2,
)
