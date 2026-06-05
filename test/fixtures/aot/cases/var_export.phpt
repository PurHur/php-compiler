--TEST--
AOT: var_export() (#5190)
--FILE--
<?php
echo var_export(['k' => 9], true), "\n";
echo var_export(false, true), "\n";
--EXPECT--
array (
  'k' => 9,
)
false
