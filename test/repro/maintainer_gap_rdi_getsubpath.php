<?php
declare(strict_types=1);

$root = sys_get_temp_dir() . '/phpc_rdi_sub_' . getmypid();
if (!is_dir($root) && !mkdir($root) && !is_dir($root)) {
    fwrite(STDERR, "mkdir root failed\n");
    exit(1);
}
$nested = $root . '/nested';
if (!is_dir($nested) && !mkdir($nested) && !is_dir($nested)) {
    fwrite(STDERR, "mkdir nested failed\n");
    exit(1);
}
$file = $nested . '/a.txt';
if (false === file_put_contents($file, 'x')) {
    fwrite(STDERR, "write failed path=" . var_export($file, true) . "\n");
    exit(1);
}
$it = new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS);
$rii = new RecursiveIteratorIterator($it);
$found = false;
foreach ($rii as $path => $info) {
    if (!str_ends_with((string) $path, 'a.txt')) {
        continue;
    }
    $inner = $rii->getInnerIterator();
    echo 'class=', get_class($inner), "\n";
    echo 'has_getSubPath=', (int) method_exists($inner, 'getSubPath'), "\n";
    echo 'has_getSubPathname=', (int) method_exists($inner, 'getSubPathname'), "\n";
    if (method_exists($inner, 'getSubPath')) {
        echo 'sub=', $inner->getSubPath(), "\n";
        echo 'subname=', $inner->getSubPathname(), "\n";
    }
    $found = true;
    break;
}
echo 'found=', (int) $found, "\n";
@unlink($file);
@rmdir($nested);
@rmdir($root);
