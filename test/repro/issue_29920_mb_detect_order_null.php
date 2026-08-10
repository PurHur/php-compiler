<?php
declare(strict_types=1);
try {
  var_export(mb_detect_order(null));
  echo "\n";
} catch (Throwable $e) {
  echo get_class($e), ": ", $e->getMessage(), "\n";
}
