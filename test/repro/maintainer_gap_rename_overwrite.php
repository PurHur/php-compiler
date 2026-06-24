<?php

declare(strict_types=1);

$base = __DIR__ . '/rename_overwrite_fixture';
if (!is_dir($base)) {
    mkdir($base, 0777, true);
}
$src = $base . '/src.txt';
$dst = $base . '/dst.txt';
file_put_contents($src, 'source');
file_put_contents($dst, 'existing');

$ok = rename($src, $dst);
echo 'rename=' . var_export($ok, true) . "\n";
echo 'src_exists=' . var_export(file_exists($src), true) . "\n";
echo 'dst=' . var_export(file_get_contents($dst), true) . "\n";

@unlink($src);
@unlink($dst);
