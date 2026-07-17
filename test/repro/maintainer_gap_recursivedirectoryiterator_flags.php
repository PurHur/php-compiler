<?php
$dir = sys_get_temp_dir() . "/rdi_gap_" . getmypid();
@mkdir($dir);
file_put_contents("$dir/x.txt", "x");
@mkdir("$dir/sub");
echo "default_flags=", (new RecursiveDirectoryIterator($dir))->getFlags(), "\n";
echo "fi_default_flags=", (new FilesystemIterator($dir))->getFlags(), "\n";
$names = [];
foreach (new RecursiveDirectoryIterator($dir) as $v) {
  $names[] = $v->getFilename();
}
sort($names);
echo "default_entries=", implode(",", $names), "\n";
$names2 = [];
foreach (new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS) as $v) {
  $names2[] = $v->getFilename();
}
sort($names2);
echo "skip_dots_entries=", implode(",", $names2), "\n";
