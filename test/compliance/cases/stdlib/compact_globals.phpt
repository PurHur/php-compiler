--TEST--
stdlib compact() — $GLOBALS-only variable names (#11743, basic_functions.c)
--FILE--
<?php
$GLOBALS['phpc_compact_globals_probe'] = 42;
var_export(compact('phpc_compact_globals_probe'));
echo "\n";
$z = 9;
var_export(compact('z'));
echo "\n";
--EXPECT--
array (
  'phpc_compact_globals_probe' => 42,
)
array (
  'z' => 9,
)
