<?php

declare(strict_types=1);

/**
 * Issue #6235 — Imagick read/resize/write subset via ImageMagick CLI.
 *
 * Run:
 *   PHP_COMPILER_ENABLE_IMAGICK=1 php bin/vm.php test/repro/issue_6235_imagick_read_resize.php
 */

if (!class_exists('Imagick')) {
    echo "skip: Imagick not advertised (set PHP_COMPILER_ENABLE_IMAGICK=1 with ImageMagick CLI)\n";
    exit(0);
}

$root = dirname(__DIR__, 2);
$src = $root.'/test/fixtures/imagick/red_3x3.png';
if (!is_file($src)) {
    echo "fail: missing fixture\n";
    exit(1);
}

$im = new Imagick();
if (!$im->readImage($src)) {
    echo "fail: readImage\n";
    exit(1);
}
$w = $im->getImageWidth();
$h = $im->getImageHeight();
echo "size={$w}x{$h}\n";
if (3 !== $w || 3 !== $h) {
    echo "fail: dimensions\n";
    exit(1);
}

if (!$im->resizeImage(6, 6, 22, 1.0, false)) {
    echo "fail: resizeImage\n";
    exit(1);
}
$w2 = $im->getImageWidth();
$h2 = $im->getImageHeight();
echo "resized={$w2}x{$h2}\n";
if (6 !== $w2 || 6 !== $h2) {
    echo "fail: resized dimensions\n";
    exit(1);
}

$out = sys_get_temp_dir().'/phpc_imagick_6235_out.png';
@unlink($out);
if (!$im->writeImage($out)) {
    echo "fail: writeImage\n";
    exit(1);
}
if (!is_file($out)) {
    echo "fail: output missing\n";
    exit(1);
}

$check = new Imagick();
$check->readImage($out);
echo 'out='.$check->getImageWidth().'x'.$check->getImageHeight()."\n";
@unlink($out);

echo "ok\n";
