<?php
foreach (["spl_object_hash","spl_object_id","get_object_vars","get_mangled_object_vars"] as $fn) {
  foreach ([false, true] as $v) {
    try { $fn($v); } catch (Throwable $e) { echo $fn, ":", $e->getMessage(), PHP_EOL; }
  }
}
try { get_class(false); } catch (Throwable $e) { echo "get_class:", $e->getMessage(), PHP_EOL; }

