--TEST--
NoRewindIterator::rewind() is a no-op on inner (php-src ext/spl/spl_iterators.c, #13170)
--FILE--
<?php
$inner = new ArrayIterator(['a', 'b']);
$inner->next();
$keyBefore = $inner->key();
$wrap = new NoRewindIterator($inner);
$wrap->rewind();
echo $inner->key() === $keyBefore ? '1' : '0', "\n";
echo $wrap->valid() ? '1' : '0', "\n";
echo $wrap->current(), "\n";
--EXPECT--
1
1
b
