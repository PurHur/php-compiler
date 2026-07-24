--TEST--
SPL CachingIterator FULL_CACHE over temporary Generator (#22876, ext/spl/spl_iterators.c)
--FILE--
<?php
function gen()
{
    yield 'a' => 1;
    yield 'b' => 2;
}

$ci = new CachingIterator(gen(), CachingIterator::FULL_CACHE);
foreach ($ci as $k => $v) {
}
var_export($ci->getCache());
echo "\n";

$ci2 = new CachingIterator(new ArrayIterator(['a' => 1, 'b' => 2]), CachingIterator::FULL_CACHE);
foreach ($ci2 as $k => $v) {
}
var_export($ci2->getCache());
echo "\n";
--EXPECT--
array (
  'a' => 1,
  'b' => 2,
)
array (
  'a' => 1,
  'b' => 2,
)
