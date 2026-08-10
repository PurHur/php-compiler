<?php
declare(strict_types=1);
try {
  $o = mb_detect_order(null);
  echo is_array($o) ? 'array' : gettype($o), ':', count($o), ':';
  echo implode(',', $o), "\n";
} catch (Throwable $e) {
  echo get_class($e), ": ", $e->getMessage(), "\n";
}
