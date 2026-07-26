<?php
try {
  var_export(max(...["a" => 1, "b" => 2]));
  echo "\n";
} catch (Throwable $e) {
  echo get_class($e), ":", $e->getMessage(), "\n";
}
try {
  var_export(min(...["a" => 3, "b" => 1]));
  echo "\n";
} catch (Throwable $e) {
  echo get_class($e), ":", $e->getMessage(), "\n";
}
try {
  var_export(max(1, 2, a: 3));
  echo "\n";
} catch (Throwable $e) {
  echo get_class($e), ":", $e->getMessage(), "\n";
}
echo max(...["0" => 5, "1" => 9]), "\n";
echo max([1, 2, 3]), "\n";
