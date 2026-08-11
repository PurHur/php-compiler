<?php
error_reporting(E_ALL);
try {
  $r = ob_start(null);
  if (ob_get_level() > 0) { ob_end_clean(); }
  fwrite(STDOUT, var_export($r, true) . "\n");
} catch (Throwable $e) {
  fwrite(STDOUT, get_class($e).": ".$e->getMessage()."\n");
}
