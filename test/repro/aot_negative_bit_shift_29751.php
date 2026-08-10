<?php
try {
  $a = 1;
  var_export($a << -1);
  echo "\n";
} catch (Throwable $e) {
  echo get_class($e), ": ", $e->getMessage(), "\n";
}
