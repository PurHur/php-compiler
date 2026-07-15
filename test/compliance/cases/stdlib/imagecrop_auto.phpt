--TEST--
stdlib imagecrop()/imagecropauto() on truecolor GdImage (#6380, ext/gd/gd.c)
--FILE--
<?php
declare(strict_types=1);

$im = imagecreatetruecolor(4, 4);
$black = imagecolorallocate($im, 0, 0, 0);
$white = imagecolorallocate($im, 255, 255, 255);
imagefill($im, 0, 0, $black);
imagesetpixel($im, 1, 1, $white);
imagesetpixel($im, 2, 1, $white);
imagesetpixel($im, 1, 2, $white);
imagesetpixel($im, 2, 2, $white);

$cropped = imagecrop($im, ['x' => 1, 'y' => 1, 'width' => 2, 'height' => 2]);
echo imagesx($cropped), 'x', imagesy($cropped), "\n";
echo imagecolorat($cropped, 0, 0), "\n";

$auto = imagecropauto($im, IMG_CROP_DEFAULT);
echo imagesx($auto), 'x', imagesy($auto), "\n";
?>
--EXPECT--
2x2
16777215
2x2
