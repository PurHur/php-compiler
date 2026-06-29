--TEST--
stdlib inline new ArrayIterator/ArrayObject call arg (#13685, ext/spl/spl_array.c)
--FILE--
<?php
function probe(mixed $x): string { return get_debug_type($x); }
echo probe(new ArrayIterator([])), "\n";
echo probe(new ArrayObject([])), "\n";
--EXPECT--
ArrayIterator
ArrayObject
