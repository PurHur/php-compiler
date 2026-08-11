<?php
foreach (["fclose","fread","get_resource_id"] as $fn) {
  foreach ([false, true] as $v) {
    try {
      if ($fn === "fread") { $fn($v, 1); }
      else { $fn($v); }
    } catch (Throwable $e) { echo $fn, ":", $e->getMessage(), PHP_EOL; }
  }
}

