<?php
$it = new CachingIterator(new ArrayIterator(["a" => 1, "b" => 2]), CachingIterator::FULL_CACHE);
foreach ($it as $_) {}
echo "methods:",
  (int) method_exists($it, "offsetExists"),
  (int) method_exists($it, "offsetGet"),
  (int) method_exists($it, "offsetSet"),
  (int) method_exists($it, "offsetUnset"),
  "\n";
try {
  echo "exists_a=", var_export($it->offsetExists("a"), true), "\n";
  echo "get_a=", var_export($it->offsetGet("a"), true), "\n";
  $it->offsetSet("z", 9);
  $it->offsetUnset("a");
  echo "cache=";
  var_export($it->getCache());
  echo "\n";
} catch (Throwable $e) {
  echo "ERR ", get_class($e), ": ", $e->getMessage(), "\n";
}
$plain = new CachingIterator(new ArrayIterator([1]));
$plain->next();
try {
  $plain->offsetExists(0);
} catch (Throwable $e) {
  echo "no_full=", get_class($e), ": ", $e->getMessage(), "\n";
}
