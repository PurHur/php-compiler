<?php
foreach (["addFromString","compress","decompress","convertToExecutable","convertToData","addEmptyDir","buildFromDirectory","buildFromIterator","addFile"] as $m) {
  echo "$m=", method_exists("PharData", $m) ? "1" : "0", "\n";
}
$tmp = sys_get_temp_dir() . "/phardata_gap_" . getmypid() . ".tar";
@unlink($tmp);
try {
  $p = new PharData($tmp);
  $p->addFromString("a.txt", "hi");
  $p->addEmptyDir("d");
  echo "exists_d=", $p->offsetExists("d") ? "1" : "0", "\n";
  echo "ok\n";
} catch (Throwable $e) {
  echo get_class($e), ": ", $e->getMessage(), "\n";
}
@unlink($tmp);
