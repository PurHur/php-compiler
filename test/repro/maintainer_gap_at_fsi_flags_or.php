<?php
$dir = sys_get_temp_dir() . "/phpc_atfsi_" . getmypid();
@mkdir($dir);
file_put_contents($dir . "/a.txt", "1");
$it = new FilesystemIterator($dir, FilesystemIterator::CURRENT_AS_PATHNAME | FilesystemIterator::SKIP_DOTS);
echo json_encode(iterator_to_array($it, false)), "\n";
foreach (glob($dir . "/*") ?: [] as $f) { @unlink($f); }
@rmdir($dir);
