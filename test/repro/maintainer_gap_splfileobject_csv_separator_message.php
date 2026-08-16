<?php
// SplFileObject::fgetcsv/fputcsv empty separator ValueError cites method + arg index (php-src spl_directory.c).
error_reporting(E_ALL);
$f = tempnam(sys_get_temp_dir(), 'sfo');
file_put_contents($f, "a,b\n");
$o = new SplFileObject($f);
try {
    $o->fgetcsv('');
} catch (Throwable $e) {
    echo 'fgetcsv: ', get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $o->fputcsv(['x'], '');
} catch (Throwable $e) {
    echo 'fputcsv: ', get_class($e), ': ', $e->getMessage(), "\n";
}
@unlink($f);
