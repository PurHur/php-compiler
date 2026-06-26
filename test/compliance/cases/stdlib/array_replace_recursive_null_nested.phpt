--TEST--
stdlib array_replace_recursive() nested inline null replacement (#12258, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
var_export(array_replace_recursive(['a' => ['b' => 1]], ['a' => null]));
echo "\n";
?>
--EXPECT--
array (
  'a' => NULL,
)
