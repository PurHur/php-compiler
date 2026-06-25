--TEST--
stdlib get_class_methods() on built-in Iterator interface (#11786)
--FILE--
<?php
$methods = get_class_methods(Iterator::class);
sort($methods);
echo implode(',', $methods), "\n";
echo get_class_methods(IteratorAggregate::class) === ['getIterator'] ? 'agg=ok' : 'agg=fail';
echo "\n";
--EXPECT--
current,key,next,rewind,valid
agg=ok
