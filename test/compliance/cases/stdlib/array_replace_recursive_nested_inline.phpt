--TEST--
stdlib array_replace_recursive() — nested inline array literal args (#10196, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
var_export(array_replace_recursive(['a' => ['b' => 1]], ['a' => ['b' => 2, 'c' => 3]]));
echo "\n";
?>
--EXPECT--
array (
  'a' => array (
    'b' => 2,
    'c' => 3,
  ),
)
