--TEST--
stdlib array_merge_recursive() renumbers int keys like array_merge (#26559)
--FILE--
<?php
// Top-level: all int keys renumber from 0 (php-src PHP_FUNCTION starts empty).
var_export(array_merge_recursive([1 => 'a'], [1 => 'b']));
echo "\n";
var_export(array_merge_recursive([1 => 'a', 5 => 'b']));
echo "\n";
var_export(array_merge_recursive([5 => 'x', 'k' => 1], [9 => 'y', 'k' => 2]));
echo "\n";
// Nested under string key: dest int keys preserved; overlay int keys append.
var_export(array_merge_recursive(['k' => [1 => 'a']], ['k' => [1 => 'b']]));
echo "\n";
// Nested int parents do not deep-merge — they append like top-level int keys.
var_export(array_merge_recursive([1 => ['a' => 1]], [1 => ['b' => 2]]));
echo "\n";
// Plain array_merge unchanged.
var_export(array_merge([1 => 'a'], [1 => 'b']));
echo "\n";
--EXPECT--
array (
  0 => 'a',
  1 => 'b',
)
array (
  0 => 'a',
  1 => 'b',
)
array (
  0 => 'x',
  'k' => array (
    0 => 1,
    1 => 2,
  ),
  1 => 'y',
)
array (
  'k' => array (
    1 => 'a',
    2 => 'b',
  ),
)
array (
  0 => array (
    'a' => 1,
  ),
  1 => array (
    'b' => 2,
  ),
)
array (
  0 => 'a',
  1 => 'b',
)
