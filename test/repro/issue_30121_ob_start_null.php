<?php
declare(strict_types=1);
error_reporting(E_ALL);
try {
  $r = ob_start(null);
  $level = ob_get_level();
  if ($level > 0) { ob_end_clean(); }
  echo ($r ? 'true' : 'false'), ' level_was=', $level, "\n";
} catch (Throwable $e) {
  echo get_class($e), ': ', $e->getMessage(), "\n";
}
