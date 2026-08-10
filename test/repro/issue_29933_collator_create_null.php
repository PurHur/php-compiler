<?php
declare(strict_types=1);
try {
  var_export(collator_create(null));
  echo "\n";
} catch (Throwable $e) {
  echo get_class($e), ": ", $e->getMessage(), "\n";
}
