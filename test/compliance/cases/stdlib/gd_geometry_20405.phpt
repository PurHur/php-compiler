--TEST--
stdlib imagerotate/imagescale/imageconvolution/imagecopyresized (#20405, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

foreach (['imagerotate', 'imagescale', 'imageconvolution', 'imagecopyresized'] as $fn) {
    echo $fn, '=', (int) function_exists($fn), "\n";
}

$im = imagecreatetruecolor(8, 8);
$red = imagecolorallocate($im, 255, 0, 0);
$blue = imagecolorallocate($im, 0, 0, 255);
imagefilledrectangle($im, 0, 0, 3, 7, $red);
imagefilledrectangle($im, 4, 0, 7, 7, $blue);

$rot = imagerotate($im, 90, 0);
echo 'rotate_type=', get_debug_type($rot), "\n";
echo 'rotate_size=', imagesx($rot), 'x', imagesy($rot), "\n";
echo 'rotate_corner=', imagecolorat($rot, 0, 7) === $red ? 'red' : 'other', "\n";

$scaled = imagescale($im, 4, 4, IMG_NEAREST_NEIGHBOUR);
echo 'scale_type=', get_debug_type($scaled), "\n";
echo 'scale_size=', imagesx($scaled), 'x', imagesy($scaled), "\n";
echo 'scale_px=', imagecolorat($scaled, 0, 0) === $red ? 'red' : 'other', "\n";

$conv = imagecreatetruecolor(4, 4);
$white = imagecolorallocate($conv, 200, 100, 50);
imagefilledrectangle($conv, 0, 0, 3, 3, $white);
$before = imagecolorat($conv, 1, 1);
$ok = imageconvolution($conv, [
    [0, 0, 0],
    [0, 1, 0],
    [0, 0, 0],
], 1.0, 0.0);
echo 'conv_ok=', (int) $ok, "\n";
echo 'conv_same=', imagecolorat($conv, 1, 1) === $before ? '1' : '0', "\n";

$dst = imagecreatetruecolor(4, 4);
imagefilledrectangle($dst, 0, 0, 3, 3, imagecolorallocate($dst, 0, 0, 0));
$src = imagecreatetruecolor(2, 2);
$g = imagecolorallocate($src, 0, 255, 0);
imagefilledrectangle($src, 0, 0, 1, 1, $g);
echo 'resized=', (int) imagecopyresized($dst, $src, 0, 0, 0, 0, 4, 4, 2, 2), "\n";
echo 'resized_px=', imagecolorat($dst, 3, 3) === $g ? 'green' : 'other', "\n";
?>
--EXPECT--
imagerotate=1
imagescale=1
imageconvolution=1
imagecopyresized=1
rotate_type=GdImage
rotate_size=8x8
rotate_corner=red
scale_type=GdImage
scale_size=4x4
scale_px=red
conv_ok=1
conv_same=1
resized=1
resized_px=green
