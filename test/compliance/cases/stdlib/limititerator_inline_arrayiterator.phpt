--TEST--
stdlib LimitIterator inline nested ArrayIterator constructor (#12916, ext/spl/spl_iterators.c)
--FILE--
<?php
var_export(iterator_to_array(new LimitIterator(new ArrayIterator([1, 2, 3]), 1, 1)));
--EXPECT--
array (
  1 => 2,
)
