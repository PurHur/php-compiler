<?php
declare(strict_types=1);

$fp = fopen('php://memory', 'w');
fwrite($fp, 'x');
ob_start();
$fsync = fsync($fp);
$fsyncOut = ob_get_clean();
echo 'fsync=', var_export($fsync, true), "\n";
echo 'fsync_warn=', str_contains($fsyncOut, "Can't fsync this stream!") ? 'yes' : 'no', "\n";

$fp2 = fopen('php://memory', 'w');
fwrite($fp2, 'x');
ob_start();
$fdatasync = fdatasync($fp2);
$fdatasyncOut = ob_get_clean();
echo 'fdatasync=', var_export($fdatasync, true), "\n";
echo 'fdatasync_warn=', str_contains($fdatasyncOut, "Can't fsync this stream!") ? 'yes' : 'no', "\n";

$path = tempnam(sys_get_temp_dir(), 'phpc_fsync_warn_');
$fp3 = fopen($path, 'w');
fwrite($fp3, 'y');
ob_start();
$ok = fsync($fp3);
$okOut = ob_get_clean();
fclose($fp3);
@unlink($path);
echo 'file=', var_export($ok, true), "\n";
echo 'file_warn=', str_contains($okOut, "Can't fsync this stream!") ? 'yes' : 'no', "\n";
