<?php
// Repro for #35656 — stat()/lstat() LLVM module verify when fail path uses phi merge.
declare(strict_types=1);

$path = tempnam(sys_get_temp_dir(), 'phpc_stat35656_');
if (!is_string($path)) {
    echo "skip\n";
    exit(0);
}
touch($path);
$s = stat($path);
var_export($s !== false);
echo "\n";
$l = lstat($path);
var_export($l !== false);
echo "\n";
@unlink($path);
