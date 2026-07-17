<?php
echo "has_debug=", var_export(method_exists("MultipleIterator", "__debugInfo"), true), "\n";
$mi = new MultipleIterator();
$mi->attachIterator(new ArrayIterator([1, 2]), "x");
try {
  $dbg = $mi->__debugInfo();
  echo "keys=", implode(",", array_keys($dbg)), "\n";
  $bag = $dbg["\0SplObjectStorage\0storage"] ?? null;
  echo "rows=", is_array($bag) ? count($bag) : "null", "\n";
  if (is_array($bag) && isset($bag[0]["inf"])) {
    echo "inf0=", var_export($bag[0]["inf"], true), "\n";
  }
} catch (Throwable $e) {
  echo "ERR ", get_class($e), ": ", $e->getMessage(), "\n";
}
