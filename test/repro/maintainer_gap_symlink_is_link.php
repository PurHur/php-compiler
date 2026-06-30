<?php
declare(strict_types=1);

$dir = sys_get_temp_dir() . '/phpc_symlink_' . bin2hex(random_bytes(4));
if (!mkdir($dir) && !is_dir($dir)) {
    echo "mkdir fail\n";
    exit(1);
}
$target = $dir . '/target.txt';
file_put_contents($target, 'payload');
$link = $dir . '/link';
if (!symlink($target, $link)) {
    echo "symlink fail\n";
    exit(1);
}
if (!is_link($link)) {
    echo "is_link fail\n";
    exit(1);
}
if (readlink($link) !== $target) {
    echo "readlink fail got ", var_export(readlink($link), true), "\n";
    exit(1);
}
echo "ok\n";
