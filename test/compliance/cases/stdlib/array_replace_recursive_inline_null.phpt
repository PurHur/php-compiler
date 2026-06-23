--TEST--
stdlib array_replace_recursive() inline literal with null element value (#10612, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
var_export(array_replace_recursive(['a' => 1], ['a' => null]));
echo "\n";
?>
--EXPECT--
array (
  'a' => NULL,
)
