<?php
$td = sys_get_temp_dir()."/gi_".getmypid();
@mkdir($td);
file_put_contents("$td/a.txt", "x");
$cases = [
  "0" => 0,
  "CAP" => FilesystemIterator::CURRENT_AS_PATHNAME,
  "KAF" => FilesystemIterator::KEY_AS_FILENAME,
  "SKIP" => FilesystemIterator::SKIP_DOTS,
  "CAP|KAF" => FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::KEY_AS_FILENAME,
];
foreach ($cases as $label => $flags) {
  $it = new GlobIterator($td."/*.txt", $flags);
  $n = 0;
  $sample = null;
  foreach ($it as $k => $v) {
    ++$n;
    if (null === $sample) {
      $sample = ["k"=>$k, "vtype"=>is_object($v)?get_class($v):gettype($v), "v"=>is_string($v)?$v:(is_object($v)?$v->getFilename():$v)];
    }
  }
  echo "$label n=$n";
  if ($sample) {
    echo " k=", json_encode($sample["k"]), " vtype=", $sample["vtype"], " v=", json_encode($sample["v"]);
  }
  echo "\n";
}
@unlink("$td/a.txt");
@rmdir($td);
