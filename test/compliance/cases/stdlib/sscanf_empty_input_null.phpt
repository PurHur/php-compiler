--TEST--
stdlib sscanf() empty input — NULL not array with NULL slots (#10976, ext/standard/formatted_io.c)
--FILE--
<?php
echo var_export(sscanf('', '%d'), true), "\n";
echo var_export(sscanf('', '%s'), true), "\n";
echo var_export(sscanf('abc', '%d'), true), "\n";
--EXPECT--
NULL
NULL
array (
  0 => NULL,
)
