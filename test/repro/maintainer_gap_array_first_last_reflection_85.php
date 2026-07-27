<?php
foreach (["array_first", "array_last"] as $fn) {
  $rf = new ReflectionFunction($fn);
  $names = [];
  foreach ($rf->getParameters() as $p) { $names[] = $p->getName(); }
  echo $fn, " params=[", implode(",", $names), "] n=", $rf->getNumberOfParameters(), "\n";
}
try {
  var_dump(array_first(array: [10, 20]));
} catch (Throwable $e) {
  echo get_class($e), ": ", $e->getMessage(), "\n";
}
try {
  var_dump(array_last(array: [10, 20]));
} catch (Throwable $e) {
  echo get_class($e), ": ", $e->getMessage(), "\n";
}
