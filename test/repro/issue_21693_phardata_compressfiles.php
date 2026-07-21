<?php
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/phar21693_' . getmypid() . '_' . mt_rand();
if (!is_dir($dir)) {
    mkdir($dir, 0777, true);
}
$tar = $dir . '/pcf.tar';
if (is_file($tar)) {
    unlink($tar);
}

$p = new PharData($tar);
$p['a.txt'] = str_repeat('h', 20);
try {
    $p->compressFiles(Phar::GZ);
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
