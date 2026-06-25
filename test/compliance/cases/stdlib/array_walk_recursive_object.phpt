--TEST--
stdlib array_walk_recursive() walks stdClass properties and nested arrays (#11410, ext/standard/array.c)
--FILE--
<?php
declare(strict_types=1);
$o = (object) ['a' => ['x' => 1]];
array_walk_recursive($o, static function (&$v): void {
    $v++;
});
var_export($o);
echo "\n";
?>
--EXPECT--
(object) array (
  'a' => 
  array (
    'x' => 2,
  ),
)
