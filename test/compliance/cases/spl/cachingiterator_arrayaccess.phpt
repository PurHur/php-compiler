--TEST--
SPL CachingIterator ArrayAccess — FULL_CACHE offset* (#20143, ext/spl/spl_iterators.c)
--FILE--
<?php
$plain = new CachingIterator(new ArrayIterator([1]));
$plain->next();
try {
    $plain->offsetExists(0);
    echo "no-exception\n";
} catch (BadMethodCallException $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}

$it = new CachingIterator(new ArrayIterator(['a' => 1, 'b' => 2]), CachingIterator::FULL_CACHE);
foreach ($it as $_) {
}
echo 'methods=',
    (int) method_exists($it, 'offsetExists'),
    (int) method_exists($it, 'offsetGet'),
    (int) method_exists($it, 'offsetSet'),
    (int) method_exists($it, 'offsetUnset'),
    "\n";
echo 'exists_a=', var_export($it->offsetExists('a'), true), "\n";
echo 'get_a=', var_export($it->offsetGet('a'), true), "\n";
$it->offsetSet('z', 9);
$it->offsetUnset('a');
$cache = $it->getCache();
ksort($cache);
echo 'cache=';
var_export($cache);
echo "\n";

// ArrayAccess syntax + RecursiveCachingIterator inheritance
$it2 = new CachingIterator(new ArrayIterator([10, 20, 30]), CachingIterator::FULL_CACHE);
foreach ($it2 as $_) {
}
echo 'isset0=', var_export(isset($it2[0]), true), ' get0=', var_export($it2[0], true), "\n";
$it2[5] = 55;
unset($it2[1]);
$c2 = $it2->getCache();
ksort($c2);
echo 'cache2=';
var_export($c2);
echo "\n";

$rci = new RecursiveCachingIterator(new RecursiveArrayIterator(['x' => 1]), CachingIterator::FULL_CACHE);
foreach ($rci as $_) {
}
echo 'rci_get=', var_export($rci->offsetGet('x'), true), "\n";
?>
--EXPECT--
BadMethodCallException: CachingIterator does not use a full cache (see CachingIterator::__construct)
methods=1111
exists_a=true
get_a=1
cache=array (
  'b' => 2,
  'z' => 9,
)
isset0=true get0=10
cache2=array (
  0 => 10,
  2 => 30,
  5 => 55,
)
rci_get=1
