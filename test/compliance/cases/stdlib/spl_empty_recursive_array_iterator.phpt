--TEST--
SPL EmptyIterator / RecursiveArrayIterator / OuterIterator (issue #6593)
--FILE--
<?php
var_export([
    class_exists('EmptyIterator'),
    class_exists('RecursiveArrayIterator'),
    interface_exists('OuterIterator'),
    interface_exists('RecursiveIterator'),
]);
echo "\n";

$it = new EmptyIterator();
var_export($it->valid());
echo "\n";

$rai = new RecursiveArrayIterator([1 => [2, 3]]);
var_export($rai->hasChildren());
echo "\n";

$rai->rewind();
var_export($rai->valid());
echo "\n";
var_export($rai->key());
echo "\n";
var_export($rai->current());
echo "\n";
--EXPECT--
array (
  0 => true,
  1 => true,
  2 => true,
  3 => true,
)
false
true
true
1
array (
  0 => 2,
  1 => 3,
)
