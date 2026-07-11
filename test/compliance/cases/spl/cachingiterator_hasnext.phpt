--TEST--
SPL CachingIterator::hasNext() after next() on one-item inner iterator (#13123, ext/spl/spl_iterators.c)
--FILE--
<?php
$it = new CachingIterator(new ArrayIterator([1]));
$it->next();
var_export($it->valid());
echo "\n";
var_export($it->hasNext());
echo "\n";
var_export($it->current());
echo "\n";
--EXPECT--
true
false
1
